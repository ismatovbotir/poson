<?php

namespace App\Actions\Telegram;

use App\Enums\OrderStatus;
use App\Enums\TelegramDirection;
use App\Enums\TelegramMessageKind;
use App\Enums\TelegramMessageStatus;
use App\Jobs\DeliverOrderStatusMessage;
use App\Models\Order;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Support\Telegram\Bot\BotText;
use App\Support\Telegram\MiniApp\MiniAppAudience;
use App\Support\Telegram\MiniApp\OrderStatusMessage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Store and queue one customer order status message (plan 20 M5 §6.4,
 * MA-14, MA-25, MA-27): only for a real (non-test) order placed in the Mini
 * App (orders.telegram_user_id) whose customer is in the Mini App audience,
 * and only for OrderStatusMessage::STATUSES.
 *
 * The message is an outbound system row of that conversation (user_id
 * null, visible in the inbox, lang text only). The unique (order_id,
 * order_status) key decides: a second call (listener retry, a duplicate
 * event) hits UniqueConstraintViolationException and queues nothing; any
 * other database error is thrown. The
 * delivery job carries ids only and is queued after commit (MA-24).
 */
class QueueOrderStatusMessage
{
    /**
     * @return int|null The new telegram_messages id, or null when nothing was queued
     */
    public function handle(int $orderId, OrderStatus $to): ?int
    {
        if (! OrderStatusMessage::announces($to)) {
            return null;
        }

        return DB::transaction(function () use ($orderId, $to): ?int {
            $order = Order::query()->whereKey($orderId)->first(['id', 'number', 'is_test', 'telegram_user_id']);

            if (! $order instanceof Order || $order->is_test || $order->telegram_user_id === null) {
                return null;
            }

            $user = TelegramUser::query()->find($order->telegram_user_id);

            if (! $user instanceof TelegramUser || ! MiniAppAudience::includes($user)) {
                return null;
            }

            try {
                // A savepoint, so a duplicate leaves the outer transaction usable.
                $message = DB::transaction(fn (): TelegramMessage => TelegramMessage::query()->forceCreate([
                    'telegram_user_id' => $user->id,
                    'direction' => TelegramDirection::Out,
                    'kind' => TelegramMessageKind::Text,
                    'text' => OrderStatusMessage::text($order, $to, BotText::locale($user)),
                    'status' => TelegramMessageStatus::Pending,
                    'order_id' => $order->id,
                    'order_status' => $to,
                ]));
            } catch (UniqueConstraintViolationException) {
                // Already queued for this (order, to-status): nothing more (MA-25). Any other error surfaces.
                return null;
            }

            $messageId = $message->id;

            dispatch(new DeliverOrderStatusMessage($messageId, $order->id))->afterCommit();

            return $messageId;
        });
    }
}
