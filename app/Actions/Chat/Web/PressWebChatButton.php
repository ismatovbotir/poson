<?php

namespace App\Actions\Chat\Web;

use App\Actions\Chat\AnswerProductSearch;
use App\Actions\Chat\RecordChatStat;
use App\Enums\ChatAuthor;
use App\Enums\ChatChannel;
use App\Enums\ChatMetric;
use App\Enums\ChatMode;
use App\Enums\LeadFlow;
use App\Models\Product;
use App\Models\WebChat;
use App\Models\WebChatMessage;
use App\Support\Chat\Adapters\WebAdapter;
use App\Support\Chat\ChatContext;
use App\Support\Chat\ChatReply;
use App\Support\Chat\Engine;
use App\Support\Chat\Skills\FaqSkill;
use App\Support\Chat\WebChatDialog;
use App\Support\Chat\WebChatIp;
use App\Support\Chat\WebChatLimits;
use App\Support\Chat\WebChatSwitch;
use App\Support\Chat\WebChatToken;
use Illuminate\Support\Facades\DB;

/**
 * A button of the website chat widget (plan 28 C5 §19.3, CB-19, WC-3,
 * WC-6, WC-7, WC-18). The client never names a target: it sends an index
 * or a rule id, and the server decides everything else.
 *
 *  - press(index): only into the stored buttons of the chat's LATEST bot
 *    message (an older message's index is a no-op, also when a newer bot
 *    message arrived meanwhile). rule → the rule's answer (active and on the
 *    web); faq → the FAQ item; product / quote → the inline quote form for
 *    that product, re-checked with Product::visible() ("unavailable" when it
 *    left the site); callback → the callback form; handoff → staff; menu →
 *    the menu. url buttons are links in the widget: pressing one does nothing.
 *  - topic(ruleId): a menu entry; only isMenuRule(id, Web).
 *  - menu(): the "Menu" reset path: an open form closes, a chat that does
 *    not wait for staff goes back to the bot, a new AI context starts.
 *  - handoff(): the "Talk to a manager" button.
 *
 * Any other value (non-int, negative, out of range, "1e3", an array) is a
 * no-op (IGNORED), never an error. Order like PostVisitorMessage: switch,
 * cookie (a banned chat is "unavailable"), limits before any transaction
 * (the per-chat budget is shared with typed messages), then the input.
 * topic() and handoff() may start the chat (StartWebChat with all its
 * limits; pressing with the consent line shown counts as consent, W-2);
 * press() and menu() never do (there are no stored buttons yet).
 */
class PressWebChatButton
{
    public function __construct(
        private readonly ResolveWebChat $resolve,
        private readonly StartWebChat $start,
        private readonly Engine $engine,
        private readonly DeliverWebChatReply $deliver,
        private readonly AnswerProductSearch $search,
        private readonly FaqSkill $faq,
        private readonly RecordChatStat $stats,
        private readonly WebChatToken $token,
    ) {}

    public function press(mixed $index, string $locale): PostedMessage
    {
        $locale = self::locale($locale);
        [$chat, $refused] = $this->chat(false, $locale, null);

        if ($refused !== null) {
            return $refused;
        }

        if (! is_int($index) || $index < 0 || $index >= WebAdapter::MAX_BUTTONS) {
            return new PostedMessage(PostedMessage::IGNORED, $chat->id);
        }

        $latest = $this->latestBotMessage($chat);
        $button = is_array($latest?->buttons) ? ($latest->buttons[$index] ?? null) : null;

        if (! is_array($button) || ! is_string($button['type'] ?? null)) {
            return new PostedMessage(PostedMessage::IGNORED, $chat->id);
        }

        $answer = $this->answer($chat, $button, $locale);

        if ($answer === null) {
            return new PostedMessage(PostedMessage::IGNORED, $chat->id);
        }

        [$reply, $dialog] = $answer;
        $label = isset($button['label']) && is_string($button['label']) ? $button['label'] : null;

        return $this->write($chat, $label, $locale, $reply, $dialog, $latest->id);
    }

    public function topic(mixed $ruleId, string $locale, ?string $firstPage = null): PostedMessage
    {
        $locale = self::locale($locale);

        // A cheap shape check first: a bogus id never starts a chat (the cache-only isMenuRule follows the limits).
        if (! is_int($ruleId) || $ruleId < 1) {
            return new PostedMessage(PostedMessage::IGNORED);
        }

        [$chat, $refused] = $this->chat(true, $locale, $firstPage, fn (): bool => $this->engine->isMenuRule($ruleId, ChatChannel::Web));

        if ($refused !== null) {
            return $refused;
        }

        $context = new ChatContext(ChatChannel::Web, $locale, '');
        $reply = $this->engine->respondToRule($context, $ruleId);

        if ($reply === null) {
            return new PostedMessage(PostedMessage::IGNORED, $chat->id);
        }

        $label = collect($this->engine->menu($context)->buttons)->firstWhere('id', $ruleId)['label'] ?? null;

        return $this->write($chat, is_string($label) ? $label : null, $locale, $this->searchMarker($chat, $context, $reply), null);
    }

    public function menu(string $locale): PostedMessage
    {
        $locale = self::locale($locale);
        [$chat, $refused] = $this->chat(false, $locale, null);

        if ($refused !== null) {
            return $refused;
        }

        $reply = $this->engine->menu(new ChatContext(ChatChannel::Web, $locale, ''));

        return $this->write($chat, null, $locale, $reply, null, null, function (WebChat $locked): void {
            if ($locked->mode === ChatMode::Manager && ! $locked->isWaitingForStaff()) {
                $locked->forceFill(['mode' => ChatMode::Bot]);
            }

            $locked->resetAiContext();
        });
    }

    public function handoff(string $locale, ?string $firstPage = null): PostedMessage
    {
        $locale = self::locale($locale);
        [$chat, $refused] = $this->chat(true, $locale, $firstPage);

        if ($refused !== null) {
            return $refused;
        }

        return $this->write($chat, __('chatbot.buttons.handoff', [], $locale), $locale, $this->handoffReply($locale), null);
    }

    /**
     * Switch, cookie, limits (WC-3, WC-6); optionally a new chat (WC-18).
     *
     * @param  (callable(): bool)|null  $valid  checked after the limits and before a chat is started
     * @return array{0: WebChat, 1: null}|array{0: null, 1: PostedMessage}
     */
    private function chat(bool $mayStart, string $locale, ?string $firstPage, ?callable $valid = null): array
    {
        if (! WebChatSwitch::isOpen()) {
            return [null, new PostedMessage(PostedMessage::UNAVAILABLE)];
        }

        $resolved = $this->resolve->handle();
        $chat = $resolved->chat;

        if ($resolved->banned || ($chat !== null && $chat->consent_at === null)) {
            return [null, new PostedMessage(PostedMessage::UNAVAILABLE)];
        }

        if ($chat === null && ! $mayStart) {
            return [null, new PostedMessage(PostedMessage::IGNORED)];
        }

        $ipHash = WebChatIp::hash();

        if (! WebChatLimits::message($chat, $ipHash)) {
            return [null, new PostedMessage(PostedMessage::LIMITED, $chat?->id)];
        }

        if ($valid !== null && ! $valid()) {
            return [null, new PostedMessage(PostedMessage::IGNORED, $chat?->id)];
        }

        if ($chat !== null) {
            $this->start->follow($chat, $ipHash);
        }

        if ($chat === null) {
            if (! $this->start->allow($ipHash)) {
                return [null, new PostedMessage(PostedMessage::LIMITED)];
            }

            $chat = $this->start->create($locale, $firstPage, $ipHash);
            WebChatLimits::countNewChat($chat);
        }

        return [$chat, null];
    }

    /**
     * What a stored button does: the reply and, for a form, the dialog to open.
     *
     * @param  array<string, mixed>  $button
     * @return array{0: ChatReply, 1: array<string, mixed>|null}|null
     */
    private function answer(WebChat $chat, array $button, string $locale): ?array
    {
        $id = is_int($button['id'] ?? null) && $button['id'] > 0 ? $button['id'] : null;
        $context = new ChatContext(ChatChannel::Web, $locale, '');

        return match ($button['type']) {
            'rule' => $id === null ? null : (($reply = $this->engine->respondToRule($context, $id)) === null ? null : [$this->searchMarker($chat, $context, $reply), null]),
            'faq' => $id === null ? null : [$this->faqReply($id, $locale), null],
            'product', 'quote' => $id === null ? null : $this->quoteForm($id, $locale),
            'callback' => [new ChatReply(__('chatbot.widget.form_intro', [], $locale), [], null, ChatReply::DONE), WebChatDialog::withForm(LeadFlow::Callback)],
            'handoff' => [$this->handoffReply($locale), null],
            'menu' => [$this->engine->menu($context), null],
            default => null,
        };
    }

    /**
     * @return array{0: ChatReply, 1: array<string, mixed>|null}
     */
    private function quoteForm(int $productId, string $locale): array
    {
        if (! Product::query()->visible()->whereKey($productId)->exists()) {
            return [new ChatReply(__('chatbot.search.unavailable', [], $locale), [
                ['type' => 'handoff', 'label' => __('chatbot.buttons.handoff', [], $locale)],
                ['type' => 'menu', 'label' => __('chatbot.buttons.menu', [], $locale)],
            ], null, ChatReply::DONE), null];
        }

        return [new ChatReply(__('chatbot.widget.form_intro', [], $locale), [], null, ChatReply::DONE), WebChatDialog::withForm(LeadFlow::Quote, $productId)];
    }

    private function faqReply(int $id, string $locale): ChatReply
    {
        $item = $this->faq->item($id, $locale);

        if ($item === null) {
            return new ChatReply(__('chatbot.faq.unavailable', [], $locale), [['type' => 'handoff', 'label' => __('chatbot.buttons.handoff', [], $locale)]], null, ChatReply::DONE);
        }

        return new ChatReply((string) $item['answer'], [], null, ChatReply::DONE);
    }

    /**
     * The hand-off always hands off, also when the "manager" rule is missing or off.
     */
    private function handoffReply(string $locale): ChatReply
    {
        $reply = $this->engine->respondToKey(new ChatContext(ChatChannel::Web, $locale, ''), 'manager');

        return $reply !== null && $reply->isHandoff() ? $reply : new ChatReply(__('chatbot.handoff', [], $locale), [], null, ChatReply::HANDOFF);
    }

    /**
     * A "product search" rule pressed as a button: the search prompt (no query, no text to search).
     */
    private function searchMarker(WebChat $chat, ChatContext $context, ChatReply $reply): ChatReply
    {
        return $reply->outcome === ChatReply::SEARCH ? $this->search->handle($chat, $context, $reply) : $reply;
    }

    private function latestBotMessage(WebChat $chat): ?WebChatMessage
    {
        return $chat->messages()->where('author', ChatAuthor::Bot)->latest('id')->first(['id', 'buttons']);
    }

    /**
     * One transaction with the row locked: re-check, (re-check the pressed message is still the latest), write.
     *
     * @param  array<string, mixed>|null  $dialog
     * @param  (callable(WebChat): void)|null  $before
     */
    private function write(WebChat $chat, ?string $label, string $locale, ChatReply $reply, ?array $dialog, ?int $pressedId = null, ?callable $before = null): PostedMessage
    {
        $status = DB::transaction(function () use ($chat, $label, $locale, $reply, $dialog, $pressedId, $before): string {
            $locked = WebChat::query()->whereKey($chat->id)->lockForUpdate()->first();

            if ($locked === null || $locked->isBanned() || $locked->isClosed()) {
                return PostedMessage::UNAVAILABLE;
            }

            if ($pressedId !== null && $this->latestBotMessage($locked)?->id !== $pressedId) {
                return PostedMessage::IGNORED;
            }

            if ($before !== null) {
                $before($locked);
            }

            $text = $label === null ? null : (mb_substr(trim($label), 0, WebAdapter::LABEL_MAX) ?: null);
            $this->deliver->handle($locked, $text, $locale, $reply, $dialog);

            return PostedMessage::ANSWERED;
        });

        if ($status !== PostedMessage::ANSWERED) {
            return new PostedMessage($status, $chat->id);
        }

        if (in_array($reply->outcome, [ChatReply::HIT, ChatReply::MENU], true) && $reply->ruleId !== null) {
            $this->stats->handle(ChatChannel::Web, ChatMetric::Hit, $reply->ruleId);
        }

        $this->token->refresh();

        return new PostedMessage(PostedMessage::ANSWERED, $chat->id);
    }

    private static function locale(string $locale): string
    {
        return in_array($locale, (array) config('app.supported_locales'), true) ? $locale : (string) config('app.locale');
    }
}
