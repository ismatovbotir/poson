<?php

namespace App\Actions\Chat;

use App\Actions\Telegram\StoreInboundMessage;
use App\Enums\ChatChannel;
use App\Enums\ChatMetric;
use App\Enums\ChatMode;
use App\Jobs\AnswerBotCallback;
use App\Jobs\SendBotAnswer;
use App\Models\TelegramUser;
use App\Support\Chat\Adapters\TelegramAdapter;
use App\Support\Chat\ChatContext;
use App\Support\Chat\ChatReply;
use App\Support\Chat\Engine;
use App\Support\Chat\Skills\FaqSkill;
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
 *  - menuKey(): the "FAQ" / "Order status" menu buttons → the system rule
 *    with that key.
 *
 * Bot-mode text is not stored; only counters and, when nothing matched, the
 * redacted phrase (RecordUnmatched). The answer goes out in SendBotAnswer
 * (queued after commit).
 */
class HandleChatInput
{
    public function __construct(
        private readonly Engine $engine,
        private readonly TelegramAdapter $adapter,
        private readonly FaqSkill $faq,
        private readonly RecordChatStat $stats,
        private readonly RecordUnmatched $unmatched,
        private readonly StoreInboundMessage $inbox,
    ) {}

    public function text(TelegramUser $user, UpdateParser $update): void
    {
        $context = $this->context($user, (string) $update->text);
        $reply = $this->engine->respond($context);

        if ($reply->isUnmatched()) {
            $this->unmatched->handle($user, $context->locale, $update->text);
        }

        $this->deliver($user, $reply);

        // The customer typed their request: it belongs in the inbox as written (unread, staff alert).
        if ($reply->isHandoff()) {
            // No auto-ack: the bot's hand-off reply already answers the customer.
            $this->inbox->handle($user, $update, ack: false);
        }
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

        $user->forceFill(['chat_mode' => ChatMode::Manager, 'handoff_at' => now()]);

        if (! $alreadyWaiting) {
            $this->inbox->storeSystemLine($user, __('telegram_bot.inbox.handoff_request', [], 'uz'));
        }
    }

    public function menuKey(TelegramUser $user, string $key): void
    {
        $context = $this->context($user);

        $this->deliver($user, $this->engine->respondToKey($context, $key) ?? $this->engine->menu($context));
    }

    public function callback(TelegramUser $user, UpdateParser $update): void
    {
        $parsed = TelegramAdapter::parseCallback($update->callbackData);
        $context = $this->context($user);
        $callbackId = $update->callbackQueryId;
        $reply = null;

        if ($parsed !== null && $user->isRegistered()) {
            $reply = match ($parsed['type']) {
                'menu' => $this->menu($user, $context),
                'cancel' => $this->cancel($user, $context),
                'handoff' => $this->handoffReply($user, $context),
                'rule' => $this->ruleReply($user, $context, (int) $parsed['id']),
                'button' => $this->buttonReply($user, $context, (int) $parsed['gen'], (int) $parsed['index']),
                default => null,
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

        $this->deliver($user, $reply, $callbackId);
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

        return $this->engine->menu($context);
    }

    private function cancel(TelegramUser $user, ChatContext $context): ChatReply
    {
        $user->forceFill(['bot_dialog' => null]);

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

    private function ruleReply(TelegramUser $user, ChatContext $context, int $ruleId): ?ChatReply
    {
        $offered = is_array($user->bot_dialog['rules'] ?? null) ? $user->bot_dialog['rules'] : [];

        if (! $this->engine->isMenuRule($ruleId, ChatChannel::Telegram) && ! in_array($ruleId, $offered, true)) {
            return null;
        }

        return $this->engine->respondToRule($context, $ruleId);
    }

    private function buttonReply(TelegramUser $user, ChatContext $context, int $generation, int $index): ?ChatReply
    {
        if (($user->bot_dialog['gen'] ?? null) !== $generation) {
            return null;
        }

        $button = $user->bot_dialog['buttons'][$index] ?? null;

        if (! is_array($button) || ($button['t'] ?? null) !== 'faq' || ! is_int($button['id'] ?? null)) {
            return null;
        }

        $item = $this->faq->item($button['id'], $context->locale);

        if ($item === null) {
            return new ChatReply(__('chatbot.faq.unavailable', [], $context->locale), [['type' => 'handoff', 'label' => __('chatbot.buttons.handoff', [], $context->locale)]]);
        }

        return new ChatReply($item['answer']);
    }

    /**
     * Record the outcome, apply a hand-off and queue the answer.
     */
    private function deliver(TelegramUser $user, ChatReply $reply, ?string $callbackQueryId = null): void
    {
        match ($reply->outcome) {
            ChatReply::HIT, ChatReply::MENU => $this->stats->handle(ChatChannel::Telegram, ChatMetric::Hit, $reply->ruleId ?? 0),
            ChatReply::UNMATCHED => $this->stats->handle(ChatChannel::Telegram, ChatMetric::Unmatched),
            ChatReply::HANDOFF => $this->stats->handle(ChatChannel::Telegram, ChatMetric::Handoff, $reply->ruleId ?? 0),
            default => null,
        };

        if ($reply->isHandoff()) {
            $user->forceFill(['chat_mode' => ChatMode::Manager, 'handoff_at' => now()]);
        }

        $current = is_array($user->bot_dialog) ? $user->bot_dialog : [];
        $generation = (((int) ($current['gen'] ?? 0)) + 1) % 100;
        $rendered = $this->adapter->render($reply, $generation);

        // An answer without buttons of its own (a FAQ answer) leaves the previous list in place, so
        // the buttons of the message above it keep working; a new list replaces it and makes the
        // buttons of older messages stale (their generation no longer matches).
        if ($rendered['dialog']['rules'] !== [] || $rendered['dialog']['buttons'] !== []) {
            $user->forceFill(['bot_dialog' => $rendered['dialog']]);
        } elseif ($reply->outcome === ChatReply::MENU || $reply->isHandoff()) {
            $user->forceFill(['bot_dialog' => null]);
        }

        dispatch(new SendBotAnswer($user->id, $rendered['text'], $rendered['inline'], $callbackQueryId));
    }
}
