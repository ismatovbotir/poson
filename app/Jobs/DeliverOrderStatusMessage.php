<?php

namespace App\Jobs;

use App\Enums\TelegramDirection;
use App\Enums\TelegramMessageStatus;
use App\Jobs\Concerns\DeliversStoredTelegramMessage;
use App\Models\Order;
use App\Models\TelegramMessage;
use App\Services\Telegram\TelegramClient;
use App\Support\Telegram\Bot\BotText;
use App\Support\Telegram\MiniApp\MiniAppAudience;
use App\Support\Telegram\MiniApp\OrderStatusMessage;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Deliver one stored customer order status message (plan 20 M5 §6.4,
 * MA-14, MA-17, MA-27, MA-31, MA-33). Payload: the telegram_messages id and
 * the order id only.
 *
 * When it runs, the row (outbound, pending, of this order), the order and
 * the customer are reloaded. A deleted order, a test order, an order of
 * another customer, a banned / blocked / unregistered customer, the Mini
 * App switch off or no bot token → the row becomes "skipped" and nothing
 * is sent (no token is intended as "switch off": the Mini App cannot run without the bot). Otherwise: the stored lang text as plain text to the private
 * chat telegram_users.telegram_id (never a business connection) with
 * exactly one URL button t.me/<bot>?startapp=o<id> built now from the
 * validated stored username (none → no button). Never web_app or callback
 * buttons, never the order ULID.
 *
 * Claim / 403 / 429 / backoff exactly as DeliverBotMessage
 * (DeliversStoredTelegramMessage). Queued after commit on "default".
 */
class DeliverOrderStatusMessage implements ShouldQueueAfterCommit
{
    use DeliversStoredTelegramMessage, Queueable;

    public int $maxExceptions = 5;

    public const RETRY_HOURS = 1;

    public function __construct(public int $telegramMessageId, public int $orderId)
    {
        $this->onQueue('default');
    }

    public function handle(TelegramClient $client): void
    {
        $message = TelegramMessage::query()->with('telegramUser')->find($this->telegramMessageId);

        // Only a pending status row of this order (order_id is null once the order was deleted).
        if (! $message instanceof TelegramMessage
            || $message->direction !== TelegramDirection::Out
            || $message->status !== TelegramMessageStatus::Pending
            || $message->order_status === null
            || ($message->order_id !== null && $message->order_id !== $this->orderId)) {
            return;
        }

        $user = $message->telegramUser;
        $order = $message->order_id !== null
            ? Order::query()->whereKey($message->order_id)->first(['id', 'is_test', 'telegram_user_id'])
            : null;

        if ($user === null
            || ! $order instanceof Order
            || $order->is_test
            || $order->telegram_user_id !== $user->id
            || ! MiniAppAudience::includes($user)
            || ! $client->isConfigured()) {
            TelegramMessage::query()
                ->whereKey($message->id)
                ->where('status', TelegramMessageStatus::Pending)
                ->update(['status' => TelegramMessageStatus::Skipped, 'updated_at' => now()]);

            return;
        }

        if (! $this->claim($message)) {
            return;
        }

        $button = OrderStatusMessage::button($order->id, BotText::locale($user));

        $this->deliver(
            $message,
            $user,
            fn (): array => $client->sendMessage((string) $user->telegram_id, (string) $message->text, $button !== null ? [$button] : [], null, parseMode: null),
            'Telegram order status message failed',
        );
    }
}
