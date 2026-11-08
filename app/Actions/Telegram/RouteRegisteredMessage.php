<?php

namespace App\Actions\Telegram;

use App\Actions\Chat\HandleChatInput;
use App\Enums\ChatChannel;
use App\Enums\ChatMode;
use App\Enums\TelegramMessageKind;
use App\Models\TelegramUser;
use App\Support\Chat\ChatbotSwitch;
use App\Support\Telegram\Bot\UpdateParser;

/**
 * Where a registered customer's message goes (plan 28 C1, CB-4). Called by
 * AdvanceRegistration::registered() inside HandleTelegramUpdate's
 * transaction, after BotLimits.
 *
 *  - Chat bot off for Telegram (the default): StoreInboundMessage, exactly as
 *    before the chat bot existed.
 *  - Media (photo, file, voice ...): StoreInboundMessage (TG-21, 22, 25, 26
 *    unchanged) and the conversation switches to "manager". Other non-text
 *    (contact, location ...) also goes to StoreInboundMessage, mode unchanged.
 *  - Text from a customer who is waiting for staff (unread, or open with the
 *    last inbound unanswered): StoreInboundMessage, mode set to "manager".
 *  - Text in "manager" mode: StoreInboundMessage. The conversation goes back
 *    to "bot" only after handoff_ttl_hours (default 8) from the later of the
 *    last staff reply and the customer's last message, and never
 *    while the customer is waiting for staff (unread, or open with the last
 *    inbound unanswered).
 *  - Text in "bot" mode: HandleChatInput (the rule engine).
 */
class RouteRegisteredMessage
{
    public function __construct(
        private readonly StoreInboundMessage $inbox,
        private readonly HandleChatInput $chat,
    ) {}

    public function handle(TelegramUser $user, UpdateParser $update): void
    {
        if (! ChatbotSwitch::isEnabled(ChatChannel::Telegram)) {
            $this->inbox->handle($user, $update);

            return;
        }

        if ($update->kind !== TelegramMessageKind::Text || $update->text === null) {
            if ($update->kind?->isForwardable() === true) {
                $user->forceFill(['chat_mode' => ChatMode::Manager]);
            }

            $this->inbox->handle($user, $update);

            return;
        }

        // A customer who is waiting for staff (unread, or the last message unanswered) never gets the bot,
        // whatever the stored mode says: unread from before the switch, or after a contact or location.
        if ($user->isWaitingForStaff()) {
            $user->forceFill(['chat_mode' => ChatMode::Manager]);
            $this->inbox->handle($user, $update);

            return;
        }

        if ($user->chat_mode === ChatMode::Manager && $user->hasChatTtlExpired()) {
            $user->forceFill(['chat_mode' => ChatMode::Bot]);
        }

        if ($user->chat_mode === ChatMode::Manager) {
            $this->inbox->handle($user, $update);

            return;
        }

        $this->chat->text($user, $update);
    }
}
