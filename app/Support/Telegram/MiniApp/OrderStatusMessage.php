<?php

namespace App\Support\Telegram\MiniApp;

use App\Enums\OrderStatus;
use App\Models\Order;

/**
 * Content of a customer order status message (plan 20 §6.4, MA-14, MA-17,
 * MA-26): lang text with the order number and the status label only (never
 * an amount, address, STIR, name, ULID or cancel / refund reason), and one
 * URL button t.me/<bot>?startapp=o<id> built at send time from the
 * validated stored username (none → plain text).
 */
final class OrderStatusMessage
{
    /**
     * The statuses a customer hears about: placed, confirmed, shipped,
     * completed, cancelled ("processing" is internal).
     */
    public const STATUSES = [OrderStatus::New, OrderStatus::Confirmed, OrderStatus::Shipped, OrderStatus::Completed, OrderStatus::Cancelled];

    public static function announces(OrderStatus $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }

    public static function text(Order $order, OrderStatus $status, string $locale): string
    {
        return __('telegram_bot.order_status.text', [
            'number' => (string) $order->number,
            'status' => __('enums.order_status.'.$status->value, [], $locale),
        ], $locale);
    }

    /**
     * The one URL button, or null (no valid stored bot username).
     *
     * @return array{text: string, url: string}|null
     */
    public static function button(int $orderId, string $locale): ?array
    {
        $url = BotUsername::startAppLink('o'.$orderId);

        return $url !== null ? ['text' => __('telegram_bot.order_status.button', [], $locale), 'url' => $url] : null;
    }
}
