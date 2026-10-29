<?php

namespace App\Support\Telegram;

use App\Enums\DeliveryPricing;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Support\Money;
use App\Support\Phone;

/**
 * Telegram alerts about orders and payments (plan 11b, security conditions
 * 13, 21, 22, 24), in a locale (lang/{uz,ru,en}/telegram.php). Every value
 * is escaped with e(). TEST orders start with the "🧪 TEST" marker.
 *
 * - placed (sales chat): number, total, payment method, delivery type and
 *   region, products, buyer name + phone and company name;
 * - payment alerts (finance, and sales where the plan says): number,
 *   method, amount, the reference masked to its last 4 characters, the
 *   staff member and the date — no buyer data.
 *
 * Never included: tax id (STIR / PINFL), address, email, IP, UTM tags, the
 * comment or a reason text. The "Open in admin" button only when APP_URL
 * is https.
 */
final class OrderMessage
{
    public const MAX_ITEMS = 10;

    public static function placed(Order $order, string $locale): TelegramMessage
    {
        return self::inLocale($locale, function () use ($order, $locale): TelegramMessage {
            $order->loadMissing('items');

            $lines = [
                self::marker($order).'<b>'.e(__('telegram.order.placed', ['number' => $order->number])).'</b>',
                self::line('total', Money::format($order->total)),
                self::line('payment_method', $order->payment_method->label()),
                self::line('delivery', self::delivery($order)),
            ];

            $items = $order->items->take(self::MAX_ITEMS)->map(fn (OrderItem $item): string => e($item->name)
                .($item->sku ? ' ('.e($item->sku).')' : '')
                .($item->isRollLine() ? ', '.e((string) $item->rollDescription($locale)) : ' ×'.$item->qty));

            if ($items->isNotEmpty()) {
                $more = $order->items->count() - $items->count();
                $lines[] = '<b>'.e(__('telegram.order.products')).':</b> '.$items->implode(', ')
                    .($more > 0 ? ' '.e(__('telegram.lead.more_items', ['count' => $more])) : '');
            }

            $lines[] = self::line('contact', $order->name.' · '.Phone::display($order->phone));

            if (filled($order->company_name)) {
                $lines[] = self::line('company', (string) $order->company_name);
            }

            return new TelegramMessage(implode("\n", $lines), self::button($order));
        });
    }

    /**
     * A payment event: kind = paid | delivery_paid | refunded | voided | delivery_cancelled.
     */
    public static function payment(string $kind, Order $order, OrderPayment $payment, ?string $actor, string $locale, bool $belowReference = false): TelegramMessage
    {
        return self::inLocale($locale, function () use ($kind, $order, $payment, $actor, $belowReference): TelegramMessage {
            $lines = [
                self::marker($order).'<b>'.e(__('telegram.order.payment.'.$kind, ['number' => $order->number])).'</b>',
                self::line('amount', Money::format($payment->amount)),
            ];

            if ($payment->method !== null) {
                $lines[] = self::line('method', $payment->method->label().($payment->receipt_channel ? ' · '.$payment->receipt_channel->label() : ''));
            }

            $reference = OrderPayment::maskReference($payment->reference);

            if ($reference !== null) {
                $lines[] = self::line('reference', $reference);
            }

            if ($payment->paid_on !== null && in_array($kind, ['paid', 'delivery_paid'], true)) {
                $lines[] = self::line('paid_on', $payment->paid_on->format('d.m.Y'));
            }

            if ($actor !== null) {
                $lines[] = self::line('by', $actor);
            }

            if ($belowReference) {
                $lines[] = '⚠️ '.e(__('telegram.order.below_reference'));
            }

            return new TelegramMessage(implode("\n", $lines), self::button($order));
        });
    }

    /**
     * The static UzQR code was replaced or removed (condition 24).
     */
    public static function uzqrChanged(string $action, ?string $actor, string $locale): TelegramMessage
    {
        return self::inLocale($locale, function () use ($action, $actor): TelegramMessage {
            $lines = ['⚠️ <b>'.e(__('telegram.order.uzqr.'.$action)).'</b>'];

            if ($actor !== null) {
                $lines[] = self::line('by', $actor);
            }

            $lines[] = e(__('telegram.order.uzqr.check'));

            return new TelegramMessage(implode("\n", $lines), TelegramMessage::adminButton(route('admin.settings.checkout', absolute: false), __('telegram.order.open_settings')));
        });
    }

    /**
     * The bank requisites customers pay to changed (field names only, no values).
     *
     * @param  list<string>  $fields
     */
    public static function requisitesChanged(array $fields, ?string $actor, string $locale): TelegramMessage
    {
        return self::inLocale($locale, function () use ($fields, $actor): TelegramMessage {
            $names = array_map(fn (string $field): string => __('telegram.order.requisites.fields.'.$field), $fields);
            $lines = ['⚠️ <b>'.e(__('telegram.order.requisites.title')).'</b>', self::line('fields', implode(', ', $names))];

            if ($actor !== null) {
                $lines[] = self::line('by', $actor);
            }

            $lines[] = e(__('telegram.order.uzqr.check'));

            return new TelegramMessage(implode("\n", $lines), TelegramMessage::adminButton(route('admin.settings.company', ['tab' => 'legal'], absolute: false), __('telegram.order.open_settings')));
        });
    }

    private static function delivery(Order $order): string
    {
        $text = $order->delivery_type->label();

        if ($order->region !== null) {
            $text .= ' · '.$order->region->label();
        }

        if ($order->delivery_pricing === DeliveryPricing::Separate) {
            $text .= ' · '.__('telegram.order.delivery_separate');
        } elseif ($order->delivery_amount > 0) {
            $text .= ' · '.Money::format($order->delivery_amount);
        }

        return $text;
    }

    private static function marker(Order $order): string
    {
        return $order->is_test ? '🧪 <b>TEST</b> · ' : '';
    }

    private static function line(string $label, string $value): string
    {
        return '<b>'.e(__('telegram.order.'.$label)).':</b> '.e($value);
    }

    /**
     * @return list<array{text: string, url: string}>
     */
    private static function button(Order $order): array
    {
        return TelegramMessage::adminButton(route('admin.orders.show', $order, absolute: false), __('telegram.lead.open_in_admin'));
    }

    /**
     * @param  callable(): TelegramMessage  $callback
     */
    private static function inLocale(string $locale, callable $callback): TelegramMessage
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }
}
