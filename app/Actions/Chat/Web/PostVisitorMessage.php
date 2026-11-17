<?php

namespace App\Actions\Chat\Web;

use App\Actions\Chat\AnswerProductSearch;
use App\Actions\Chat\RecordChatStat;
use App\Actions\Chat\RecordUnmatched;
use App\Enums\ChatAuthor;
use App\Enums\ChatChannel;
use App\Enums\ChatMetric;
use App\Enums\ChatMode;
use App\Events\Chat\WebChatHandedOff;
use App\Models\WebChat;
use App\Support\Chat\ChatContext;
use App\Support\Chat\ChatRedactor;
use App\Support\Chat\ChatReply;
use App\Support\Chat\Engine;
use App\Support\Chat\WebChatInput;
use App\Support\Chat\WebChatIp;
use App\Support\Chat\WebChatLimits;
use App\Support\Chat\WebChatSwitch;
use App\Support\Chat\WebChatToken;
use App\Support\Chat\WebChatVersion;
use Illuminate\Support\Facades\DB;

/**
 * A visitor's message in the website chat (plan 28 C5 §19.3; the web twin
 * of HandleChatInput). Rules only: the engine is called with AI as a literal
 * false (WC-4); Plan 33 B swaps in AiSwitch there.
 *
 * Order (WC-3, WC-6):
 *  1. every call: the widget is built and the web switch with its privacy
 *     gate is on (WebChatSwitch), the cookie resolves (ResolveWebChat: a
 *     banned chat is "unavailable" and its cookie kept; closed or expired is
 *     no chat), an existing chat has consent;
 *  2. limits (WebChatLimits), all BEFORE any transaction, so the database cache store never
 *     holds a counter row lock while the chat row is locked, and a rollback
 *     never refunds a hit: per chat chatbot.web.per_minute_chat (15) and
 *     per_day_chat (200), per IP hash per_minute_ip (60), all visitors
 *     per_minute_global (600); a first message also StartWebChat::allow();
 *  3. input: strings only, control/format characters removed (WebChatInput);
 *  4. no chat yet: StartWebChat::create() (consent, cookie, first_page);
 *  5. bot mode: the engine reply, and a SEARCH marker runs the product
 *     search (its own limiter, still outside the transaction);
 *  6. one transaction with the web_chats row locked: re-check banned /
 *     closed, ignore the same text within double_send_seconds, store the
 *     visitor message REDACTED (ChatRedactor), then
 *     - bot mode: store the bot answer with its server-stored buttons
 *       (DeliverWebChatReply, WebAdapter), open the inline lead form for a lead rule
 *       (dialog.form; the engine never runs a lead dialog on the web), and a
 *       hand-off (HandOffWebChat, after hours with the after-hours text);
 *     - manager mode: unread + 1, last_inbound_at, a re-alert at most every
 *       alert_minutes; after unanswered_max visitor messages without a staff
 *       reply nothing more is stored ("please wait");
 *     then the version is bumped after commit;
 *  7. after commit: stats and the unmatched phrase (RecordUnmatched, with
 *     its own limiters; only while WebChat::mayRecordPhrases()), and the
 *     cookie is sent again (rolling).
 *
 * $firstPage: Livewire::originalPath() or the widget's #[Locked] page prop
 * (WC-10), used only when this message starts the chat.
 */
class PostVisitorMessage
{
    public function __construct(
        private readonly ResolveWebChat $resolve,
        private readonly StartWebChat $start,
        private readonly Engine $engine,
        private readonly DeliverWebChatReply $deliver,
        private readonly AnswerProductSearch $search,
        private readonly HandOffWebChat $handoff,
        private readonly RecordChatStat $stats,
        private readonly RecordUnmatched $unmatched,
        private readonly WebChatToken $token,
    ) {}

    public function handle(mixed $text, string $locale, ?string $firstPage = null): PostedMessage
    {
        if (! WebChatSwitch::isOpen()) {
            return new PostedMessage(PostedMessage::UNAVAILABLE);
        }

        $locale = in_array($locale, (array) config('app.supported_locales'), true) ? $locale : (string) config('app.locale');
        $resolved = $this->resolve->handle();
        $chat = $resolved->chat;

        if ($resolved->banned || ($chat !== null && $chat->consent_at === null)) {
            return new PostedMessage(PostedMessage::UNAVAILABLE);
        }

        $ipHash = WebChatIp::hash();

        if (! WebChatLimits::message($chat, $ipHash)) {
            return new PostedMessage(PostedMessage::LIMITED, $chat?->id);
        }

        $input = WebChatInput::clean($text);

        if ($input === null) {
            return new PostedMessage(PostedMessage::IGNORED, $chat?->id);
        }

        if ($chat !== null) {
            $this->start->follow($chat, $ipHash);
        }

        if ($chat === null) {
            if (! $this->start->allow($ipHash)) {
                return new PostedMessage(PostedMessage::LIMITED);
            }

            $chat = $this->start->create($locale, $firstPage, $ipHash);
            // The first message counts toward the new chat's own budgets too (still outside any transaction).
            WebChatLimits::countNewChat($chat);
        }

        $context = new ChatContext(ChatChannel::Web, $locale, $input);
        $reply = $chat->mode === ChatMode::Bot ? $this->reply($chat, $context) : null;

        /** @var array{0: PostedMessage, 1: ?WebChat} $outcome */
        $outcome = DB::transaction(fn (): array => $this->store($chat->id, $input, $locale, $reply));
        [$posted, $locked] = $outcome;

        if ($posted->status === PostedMessage::ANSWERED && $reply !== null && $locked !== null) {
            $this->record($locked, $context, $reply);
        }

        if ($posted->stored()) {
            $this->token->refresh();
        }

        return $posted;
    }

    /**
     * The bot's answer, before the transaction: the engine (AI literally off,
     * WC-4) and, for a SEARCH marker, the product search.
     */
    private function reply(WebChat $chat, ChatContext $context): ChatReply
    {
        $reply = $this->engine->respond($context, false);

        return $reply->outcome === ChatReply::SEARCH ? $this->search->handle($chat, $context, $reply) : $reply;
    }

    /**
     * @return array{0: PostedMessage, 1: ?WebChat}
     */
    private function store(int $chatId, string $input, string $locale, ?ChatReply $reply): array
    {
        $locked = WebChat::query()->whereKey($chatId)->lockForUpdate()->first();

        if ($locked === null || $locked->isBanned() || $locked->isClosed()) {
            return [new PostedMessage(PostedMessage::UNAVAILABLE), null];
        }

        $stored = ChatRedactor::clean($input, (int) config('chatbot.web.message_max', 1000));
        $stored = $stored === '' ? ChatRedactor::MASK : $stored;

        if ($this->isDoubleSend($locked, $stored)) {
            return [new PostedMessage(PostedMessage::IGNORED, $locked->id), null];
        }

        if ($locked->mode === ChatMode::Manager || $reply === null) {
            return [$this->toStaff($locked, $stored, $locale), null];
        }

        return [$this->toBot($locked, $stored, $locale, $reply), $locked];
    }

    private function isDoubleSend(WebChat $locked, string $stored): bool
    {
        $last = $locked->messages()->where('author', ChatAuthor::Visitor)->latest('id')->first(['id', 'text', 'created_at']);
        $seconds = max(0, (int) config('chatbot.web.double_send_seconds', 3));

        return $last !== null && $last->text === $stored && $last->created_at !== null && $last->created_at->gte(now()->subSeconds($seconds));
    }

    private function toStaff(WebChat $locked, string $stored, string $locale): PostedMessage
    {
        $since = collect([$locked->last_staff_reply_at, $locked->handoff_at])->filter()->max();
        $unanswered = $locked->messages()
            ->where('author', ChatAuthor::Visitor)
            ->when($since !== null, fn ($query) => $query->where('created_at', '>=', $since))
            ->count();

        if ($unanswered >= max(1, (int) config('chatbot.web.unanswered_max', 20))) {
            return new PostedMessage(PostedMessage::WAIT, $locked->id);
        }

        $locked->messages()->create(['author' => ChatAuthor::Visitor, 'text' => $stored]);

        $now = now();
        $locked->forceFill([
            'locale' => $locale,
            'unread_count' => $locked->unread_count + 1,
            'last_inbound_at' => $now,
            'last_message_at' => $now,
        ]);
        $this->handoff->alert($locked, WebChatHandedOff::REASON_MESSAGE);
        $locked->save();

        WebChatVersion::bump($locked->id);

        return new PostedMessage(PostedMessage::QUEUED, $locked->id);
    }

    private function toBot(WebChat $locked, string $stored, string $locale, ChatReply $reply): PostedMessage
    {
        $this->deliver->handle($locked, $stored, $locale, $reply);

        return new PostedMessage(PostedMessage::ANSWERED, $locked->id);
    }

    /**
     * After commit: counters and the unmatched phrase (their own limiters, outside the transaction).
     */
    private function record(WebChat $chat, ChatContext $context, ChatReply $reply): void
    {
        match ($reply->outcome) {
            ChatReply::HIT, ChatReply::MENU => $this->stats->handle(ChatChannel::Web, ChatMetric::Hit, $reply->ruleId ?? 0),
            ChatReply::UNMATCHED => $this->stats->handle(ChatChannel::Web, ChatMetric::Unmatched),
            default => null,
        };

        if ($reply->isUnmatched()) {
            $this->unmatched->handle($chat, $context->locale, $context->text);
        }
    }
}
