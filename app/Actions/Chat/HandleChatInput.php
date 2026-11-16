<?php

namespace App\Actions\Chat;

use App\Actions\Telegram\StoreInboundMessage;
use App\Enums\ChatAiRole;
use App\Enums\ChatChannel;
use App\Enums\ChatMetric;
use App\Enums\ChatMode;
use App\Enums\LeadFlow;
use App\Jobs\AnswerBotCallback;
use App\Jobs\AnswerChatWithAi;
use App\Jobs\SendBotAnswer;
use App\Models\ChatAiTurn;
use App\Models\TelegramUser;
use App\Support\Chat\Adapters\TelegramAdapter;
use App\Support\Chat\AiSwitch;
use App\Support\Chat\ChatContext;
use App\Support\Chat\ChatRedactor;
use App\Support\Chat\ChatReply;
use App\Support\Chat\ChatSettings;
use App\Support\Chat\Engine;
use App\Support\Chat\LeadDialog;
use App\Support\Chat\Skills\FaqSkill;
use App\Support\Chat\WorkingHours;
use App\Support\Telegram\Bot\BotText;
use App\Support\Telegram\Bot\UpdateParser;

/**
 * Telegram input of a registered customer to the rule engine (plan 28 C1).
 * Runs inside HandleTelegramUpdate's transaction with the telegram_users row
 * locked; AdvanceRegistration / RouteRegisteredMessage call it only when the
 * chat bot is on for Telegram. The engine decides; this action does every
 * write: stats, the unmatched phrase, the hand-off (chat_mode = manager), the
 * server-side button list (bot_dialog) and the queued answer.
 *
 *  - text(): free text in bot mode → Engine::respond();
 *  - callback(): "chat:" buttons (CB-3). chat:r:N only for an active
 *    Telegram rule that is in the menu or was offered in the last answer;
 *    chat:b:I only through the buttons stored in bot_dialog; anything else is
 *    answered (spinner stopped) and ignored;
 *  - menuKey(): the "FAQ" / "Order status" / "Repair status" menu buttons →
 *    the system rule with that key.
 *
 * The engine's outcome markers run here, with the locked user (C3, C4,
 * CB-28): SEARCH → AnswerProductSearch, ORDER_STATUS / REPAIR_STATUS →
 * LookupOrderStatus / LookupRepairStatus (a typed number, or the customer's
 * own last ones as buttons), LEAD → HandleLeadDialog. A whole-message order
 * or repair number reaches the lookup before any rule, search or the
 * unmatched list (Engine::respond). While a lead dialog runs, typed text is
 * its answer and never reaches the engine. A hand-off outside working hours
 * adds the after-hours text (CB-31).
 *
 * Bot-mode text is not stored; only counters and, when nothing matched, the
 * redacted phrase (RecordUnmatched). The answer goes out in SendBotAnswer
 * (queued after commit).
 *
 * AI answers (plan 30, P3, P5, P6): with AI on, the engine may return the
 * ChatReply::AI marker. GuardAiRequest then decides, still in the webhook
 * transaction: allowed → the redacted message is stored as a user turn
 * (chat_ai_turns), its id is kept in bot_dialog["ai"] and AnswerChatWithAi
 * is queued after commit (no API call here); over a per-customer limit →
 * once per window a short "ask a manager" reply with the hand-off button
 * (never an automatic hand-off); otherwise the marker's original reply is
 * replayed exactly as without AI (a search still searches, an unmatched
 * phrase is still recorded). Any later answer drops bot_dialog["ai"], so a
 * queued AI answer for an older message is never sent (superseded). The job
 * comes back through deliverAi() or replay(). Menu, /start, cancel, hand-off
 * and a staff reply start a new AI context (ai_context_at).
 *
 * Replay from the job (L4, accepted): the raw text is never stored, so the
 * job re-runs the engine on the stored REDACTED turn. A product search then
 * searches the redacted text (masked runs of 5+ digits are gone; model names
 * like "ZD421" survive) and RecordUnmatched gets the redacted phrase.
 */
class HandleChatInput
{
    /**
     * bot_dialog key of the user turn waiting for its AI answer.
     */
    public const AI_KEY = 'ai';

    public function __construct(
        private readonly Engine $engine,
        private readonly TelegramAdapter $adapter,
        private readonly FaqSkill $faq,
        private readonly RecordChatStat $stats,
        private readonly RecordUnmatched $unmatched,
        private readonly StoreInboundMessage $inbox,
        private readonly AnswerProductSearch $search,
        private readonly LookupOrderStatus $orders,
        private readonly LookupRepairStatus $repairs,
        private readonly HandleLeadDialog $dialog,
        private readonly GuardAiRequest $guard,
    ) {}

    public function text(TelegramUser $user, UpdateParser $update): void
    {
        $context = $this->context($user, (string) $update->text);
        $state = LeadDialog::current($user, $dropped);

        if ($state === null && $dropped) {
            // The dialog expired or was invalid: say so, never treat the text as an unmatched phrase.
            $this->deliver($user, $this->dialog->expired($context->locale));

            return;
        }

        if ($state !== null) {
            $this->deliver($user, $this->dialog->text($user, $state, (string) $update->text, $context->locale), keepDialog: true);

            return;
        }

        $reply = $this->engine->respond($context, AiSwitch::isEnabled(ChatChannel::Telegram));

        if ($reply->isAi()) {
            $reply = $this->askAi($user, $context, $reply);

            if ($reply === null) {
                return;
            }
        }

        $this->answer($user, $context, $reply, $update);
    }

    /**
     * AnswerChatWithAi: deliver a filtered AI answer (already built with the
     * "AI assistant" marker and the hand-off button) and save the locked user
     * (AI-15).
     */
    public function deliverAi(TelegramUser $user, ChatReply $reply): void
    {
        $this->stats->handle(ChatChannel::Telegram, ChatMetric::Ai);
        $this->deliver($user, $reply);
        $user->save();
    }

    /**
     * AnswerChatWithAi: the AI did not answer (failed, refused, blocked,
     * capped): replay the engine's original reply as the webhook would have
     * (P5), from the stored redacted turn, and save the locked user.
     */
    public function replay(TelegramUser $user, ChatContext $context, ChatReply $reply): void
    {
        $this->answer($user, $context, $reply, null);

        if ($user->isDirty()) {
            $user->save();
        }
    }

    /**
     * Start a new AI conversation context (plan 30 §7): earlier turns are no
     * longer sent to the model. Always, also while AI is off, so turning AI
     * back on after a reset never resends the turns before it (AI-B7, L1).
     */
    public static function resetAiContext(TelegramUser $user): void
    {
        $user->forceFill(['ai_context_at' => now()]);
    }

    /**
     * Run and deliver an engine reply (the webhook, or a replay from the AI job).
     */
    private function answer(TelegramUser $user, ChatContext $context, ChatReply $reply, ?UpdateParser $update): void
    {
        [$reply, $keepDialog] = $this->run($user, $context, $reply);

        if ($keepDialog) {
            $this->deliver($user, $reply, keepDialog: true);

            return;
        }

        if ($reply->isUnmatched()) {
            $this->unmatched->handle($user, $context->locale, $context->text);
        }

        if ($reply->isHandoff() && $update === null) {
            // A replayed hand-off has no message to store: the system line alerts staff.
            $this->handoffRequested($user);
        }

        $this->deliver($user, $reply);

        // The customer typed their request: it belongs in the inbox as written (unread, staff alert).
        if ($reply->isHandoff() && $update !== null) {
            // No auto-ack: the bot's hand-off reply already answers the customer.
            $this->inbox->handle($user, $update, ack: false);
        }
    }

    /**
     * The AI marker (P3, P5, P6): queue the AI job and return null, or the
     * reply to send now (the limit notice or the original reply).
     */
    private function askAi(TelegramUser $user, ChatContext $context, ChatReply $marker): ?ChatReply
    {
        $original = $marker->original ?? $marker;
        $guard = $this->guard->handle(ChatChannel::Telegram, $user);

        if ($guard['decision'] === GuardAiRequest::LIMITED && $guard['notify']) {
            return new ChatReply(
                __('chatbot.ai.limited', [], $context->locale),
                [['type' => 'handoff', 'label' => __('chatbot.buttons.handoff', [], $context->locale)]],
                null,
                ChatReply::DONE,
            );
        }

        $text = ChatRedactor::clean($context->text, (int) config('chatbot.ai.message_max', 1000));

        if ($guard['decision'] !== GuardAiRequest::ALLOWED || trim(str_replace(ChatRedactor::MASK, '', $text)) === '') {
            return $original;
        }

        $turn = ChatAiTurn::query()->create([
            'channel' => ChatChannel::Telegram,
            'telegram_user_id' => $user->id,
            'role' => ChatAiRole::User,
            'text' => $text,
        ]);

        $dialog = is_array($user->bot_dialog) ? $user->bot_dialog : [];
        $dialog[self::AI_KEY] = $turn->id;
        $user->forceFill(['bot_dialog' => $dialog]);

        dispatch(new AnswerChatWithAi($turn->id, $user->id, ChatChannel::Telegram->value));

        return null;
    }

    /**
     * A button asked for a manager ("Contact a manager" menu button, the
     * hand-off button): the conversation goes to staff and a system line
     * (always in Uzbek, the staff language) raises unread and alerts them. A customer who already waits gets no
     * second line. Never resets waiting (CB-4).
     */
    public function handoffRequested(TelegramUser $user): void
    {
        $alreadyWaiting = $user->isWaitingForStaff();

        LeadDialog::clear($user);
        $user->forceFill(['chat_mode' => ChatMode::Manager, 'handoff_at' => now()]);
        self::resetAiContext($user);

        if (! $alreadyWaiting) {
            $this->inbox->storeSystemLine($user, __('telegram_bot.inbox.handoff_request', [], 'uz'));
        }
    }

    public function menuKey(TelegramUser $user, string $key): void
    {
        $context = $this->context($user);
        LeadDialog::clear($user);

        [$reply, $keepDialog] = $this->run($user, $context, $this->engine->respondToKey($context, $key) ?? $this->engine->menu($context));

        $this->deliver($user, $reply, keepDialog: $keepDialog);
    }

    public function callback(TelegramUser $user, UpdateParser $update): void
    {
        $parsed = TelegramAdapter::parseCallback($update->callbackData);
        $context = $this->context($user);
        $callbackId = $update->callbackQueryId;
        $reply = null;

        $keepDialog = false;

        if ($parsed !== null && $user->isRegistered()) {
            [$reply, $keepDialog] = match ($parsed['type']) {
                'menu' => [$this->menu($user, $context), false],
                'cancel' => [$this->cancel($user, $context), false],
                'handoff' => [$this->handoffReply($user, $context), false],
                'rule' => $this->ruleReply($user, $context, (int) $parsed['id']),
                'button' => $this->buttonReply($user, $context, (int) $parsed['gen'], (int) $parsed['index']),
                default => [null, false],
            };
        }

        if ($reply === null) {
            if ($callbackId !== null) {
                dispatch(new AnswerBotCallback($callbackId));
            }

            return;
        }

        if ($reply->isHandoff()) {
            $this->handoffRequested($user);
        }

        $this->deliver($user, $reply, $callbackId, $keepDialog);
    }

    private function context(TelegramUser $user, string $text = ''): ChatContext
    {
        return new ChatContext(ChatChannel::Telegram, BotText::locale($user), $text);
    }

    /**
     * The menu button: a conversation that is not waiting for staff goes
     * back to the bot at once (plan 28 §15, CB-4).
     */
    private function menu(TelegramUser $user, ChatContext $context): ChatReply
    {
        if ($user->chat_mode === ChatMode::Manager && ! $user->isWaitingForStaff()) {
            $user->forceFill(['chat_mode' => ChatMode::Bot]);
        }

        self::resetAiContext($user);

        return $this->engine->menu($context);
    }

    private function cancel(TelegramUser $user, ChatContext $context): ChatReply
    {
        $user->forceFill(['bot_dialog' => null]);
        self::resetAiContext($user);

        return $this->engine->menu($context);
    }

    /**
     * The hand-off button always hands off, even when the rules are
     * unavailable (the cache is down) or the "manager" rule is missing.
     */
    private function handoffReply(TelegramUser $user, ChatContext $context): ChatReply
    {
        $reply = $this->engine->respondToKey($context, 'manager');

        if ($reply === null || ! $reply->isHandoff()) {
            $reply = new ChatReply(__('chatbot.handoff', [], $context->locale), [], null, ChatReply::HANDOFF);
        }

        return $reply;
    }

    /**
     * @return array{0: ?ChatReply, 1: bool} the reply, and whether it belongs to a lead dialog
     */
    private function ruleReply(TelegramUser $user, ChatContext $context, int $ruleId): array
    {
        $offered = is_array($user->bot_dialog['rules'] ?? null) ? $user->bot_dialog['rules'] : [];

        if (! $this->engine->isMenuRule($ruleId, ChatChannel::Telegram) && ! in_array($ruleId, $offered, true)) {
            return [null, false];
        }

        $reply = $this->engine->respondToRule($context, $ruleId);

        return $reply === null ? [null, false] : $this->run($user, $context, $reply);
    }

    /**
     * A chat:b button: only through the list stored with the last answer
     * (its generation must be the current one), never an id from the client.
     *
     * @return array{0: ?ChatReply, 1: bool} the reply, and whether it belongs to a lead dialog
     */
    private function buttonReply(TelegramUser $user, ChatContext $context, int $generation, int $index): array
    {
        if (($user->bot_dialog['gen'] ?? null) !== $generation) {
            return [null, false];
        }

        $button = $user->bot_dialog['buttons'][$index] ?? null;

        if (! is_array($button) || ! in_array($button['t'] ?? null, TelegramAdapter::STORED, true) || ! is_int($button['id'] ?? null)) {
            return [null, false];
        }

        $locale = $context->locale;
        $id = $button['id'];

        return match ($button['t']) {
            'faq' => [$this->faqReply($id, $locale), false],
            'product' => [$this->search->product($id, $locale), false],
            'order' => [$this->orders->byId($user, $id, $locale), false],
            'repair' => [$this->repairs->byId($user, $id, $locale), false],
            'quote' => [$this->dialog->start($user, LeadFlow::Quote, $locale, productId: $id), true],
            'send', 'skip' => [$this->dialogButton($user, $button['t'], $locale), true],
            default => [null, false],
        };
    }

    private function faqReply(int $id, string $locale): ChatReply
    {
        $item = $this->faq->item($id, $locale);

        if ($item === null) {
            return new ChatReply(__('chatbot.faq.unavailable', [], $locale), [['type' => 'handoff', 'label' => __('chatbot.buttons.handoff', [], $locale)]]);
        }

        return new ChatReply($item['answer']);
    }

    private function dialogButton(TelegramUser $user, string $type, string $locale): ?ChatReply
    {
        $state = LeadDialog::current($user);

        if ($state === null) {
            return $type === 'send' ? ($this->dialog->alreadySent($user, $locale) ?? $this->dialog->expired($locale)) : $this->dialog->expired($locale);
        }

        return $type === 'send' ? $this->dialog->send($user, $state, $locale) : $this->dialog->skip($user, $state, $locale);
    }

    /**
     * Run the engine's outcome marker for this customer (CB-28).
     *
     * @return array{0: ChatReply, 1: bool} the reply, and whether it belongs to a lead dialog
     */
    private function run(TelegramUser $user, ChatContext $context, ChatReply $reply): array
    {
        $locale = $context->locale;

        return match ($reply->outcome) {
            ChatReply::SEARCH => [$this->search->handle($user, $context, $reply), false],
            ChatReply::ORDER_STATUS => [$reply->param !== null ? $this->orders->byNumber($user, $reply->param, $locale) : $this->orders->recent($user, $locale), false],
            ChatReply::REPAIR_STATUS => [$reply->param !== null ? $this->repairs->byNumber($user, $reply->param, $locale) : $this->repairs->recent($user, $locale), false],
            ChatReply::LEAD => $this->startDialog($user, $reply, $locale),
            default => [$reply, false],
        };
    }

    /**
     * @return array{0: ChatReply, 1: bool}
     */
    private function startDialog(TelegramUser $user, ChatReply $reply, string $locale): array
    {
        $flow = LeadFlow::tryFrom((string) $reply->param);

        if ($flow === null) {
            return [new ChatReply($reply->text, $reply->buttons, $reply->ruleId), false];
        }

        return [$this->dialog->start($user, $flow, $locale, $reply->ruleId), true];
    }

    /**
     * Record the outcome, apply a hand-off and queue the answer. A running
     * lead dialog survives only replies of that dialog ($keepDialog); any
     * other answer ends it.
     */
    private function deliver(TelegramUser $user, ChatReply $reply, ?string $callbackQueryId = null, bool $keepDialog = false): void
    {
        match ($reply->outcome) {
            ChatReply::HIT, ChatReply::MENU => $this->stats->handle(ChatChannel::Telegram, ChatMetric::Hit, $reply->ruleId ?? 0),
            ChatReply::UNMATCHED => $this->stats->handle(ChatChannel::Telegram, ChatMetric::Unmatched),
            ChatReply::HANDOFF => $this->stats->handle(ChatChannel::Telegram, ChatMetric::Handoff, $reply->ruleId ?? 0),
            default => null,
        };

        if ($reply->isHandoff()) {
            $user->forceFill(['chat_mode' => ChatMode::Manager, 'handoff_at' => now()]);
            self::resetAiContext($user);
            $keepDialog = false;

            if (! WorkingHours::isOpen()) {
                $afterHours = ChatSettings::text('after_hours', BotText::locale($user)) ?? __('chatbot.after_hours', [], BotText::locale($user));
                $reply = new ChatReply(trim($reply->text."\n\n".$afterHours), $reply->buttons, $reply->ruleId, $reply->outcome, $reply->param);
            }
        }

        $current = is_array($user->bot_dialog) ? $user->bot_dialog : [];
        $flow = $keepDialog ? ($current[LeadDialog::KEY] ?? null) : null;
        // Any answer supersedes an AI answer still queued for an older message.
        unset($current[LeadDialog::KEY], $current[self::AI_KEY]);
        $generation = (((int) ($current['gen'] ?? 0)) + 1) % 100;
        $rendered = $this->adapter->render($reply, $generation);

        // An answer without buttons of its own (a FAQ answer) leaves the previous list in place, so
        // the buttons of the message above it keep working; a new list replaces it and makes the
        // buttons of older messages stale (their generation no longer matches).
        if ($rendered['dialog']['rules'] !== [] || $rendered['dialog']['buttons'] !== []) {
            $dialog = $rendered['dialog'];
        } elseif ($reply->outcome === ChatReply::MENU || $reply->isHandoff()) {
            $dialog = [];
        } else {
            $dialog = $current;
        }

        if ($flow !== null) {
            $dialog[LeadDialog::KEY] = $flow;
        }

        if ($dialog !== (is_array($user->bot_dialog) ? $user->bot_dialog : [])) {
            $user->forceFill(['bot_dialog' => $dialog === [] ? null : $dialog]);
        }

        dispatch(new SendBotAnswer($user->id, $rendered['text'], $rendered['inline'], $callbackQueryId));
    }
}
