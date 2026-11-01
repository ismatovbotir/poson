<?php

namespace App\Support\Telegram;

use App\Enums\PaymentMode;
use App\Models\Order;
use App\Models\UzqrTransaction;
use App\Support\Integrations\IntegrationRegistry;
use App\Support\Money;

/**
 * Telegram alerts of dynamic UzQR (plan 11e, conditions 47, 49, 50, 53):
 * order number, amount, our own reason code, the mode — never payer phone,
 * card digits, pay_id, invoice ids, keys or bank texts. Every value is
 * escaped; TEST orders / test mode get the "🧪 TEST" marker.
 */
final class UzqrMessage
{
    public static function unmatched(UzqrTransaction $transaction, string $locale): ?TelegramMessage
    {
        $order = $transaction->order;

        if (! $order instanceof Order) {
            return null;
        }

        return self::inLocale($locale, fn (): TelegramMessage => new TelegramMessage(implode("\n", [
            self::marker($order->is_test).'⚠️ <b>'.e(__('telegram.uzqr.unmatched', ['number' => $order->number])).'</b>',
            self::line('amount', Money::format($transaction->amount)),
            self::line('reason', __('uzqr.unmatched_reasons.'.($transaction->unmatched_reason ?? 'unknown'))),
            e(__('telegram.uzqr.unmatched_hint')),
        ]), self::orderButton($order)));
    }

    public static function resolved(UzqrTransaction $transaction, ?string $actor, string $locale): ?TelegramMessage
    {
        $order = $transaction->order;

        if (! $order instanceof Order) {
            return null;
        }

        return self::inLocale($locale, function () use ($transaction, $order, $actor): TelegramMessage {
            $lines = [
                self::marker($order->is_test).'<b>'.e(__('telegram.uzqr.resolved', ['number' => $order->number])).'</b>',
                self::line('amount', Money::format($transaction->amount)),
                self::line('resolution', __('uzqr.resolutions.'.$transaction->resolution)),
            ];

            if ($actor !== null) {
                $lines[] = self::line('by', $actor);
            }

            return new TelegramMessage(implode("\n", $lines), self::orderButton($order));
        });
    }

    public static function holdReleased(UzqrTransaction $transaction, ?string $actor, string $locale): ?TelegramMessage
    {
        $order = $transaction->order;

        if (! $order instanceof Order) {
            return null;
        }

        return self::inLocale($locale, function () use ($transaction, $order, $actor): TelegramMessage {
            $lines = [
                self::marker($order->is_test).'⚠️ <b>'.e(__('telegram.uzqr.hold_released', ['number' => $order->number])).'</b>',
                self::line('amount', Money::format($transaction->amount)),
            ];

            if ($actor !== null) {
                $lines[] = self::line('by', $actor);
            }

            $lines[] = e(__('telegram.uzqr.hold_released_hint'));

            return new TelegramMessage(implode("\n", $lines), self::orderButton($order));
        });
    }

    public static function problem(string $problem, PaymentMode $mode, ?string $orderNumber, ?int $orderId, string $locale): TelegramMessage
    {
        return self::inLocale($locale, function () use ($problem, $mode, $orderNumber, $orderId): TelegramMessage {
            $lines = [self::marker($mode === PaymentMode::Test).'⚠️ <b>'.e(__('telegram.uzqr.problem')).'</b>', e(__('uzqr.problems.'.$problem))];

            if ($orderNumber !== null) {
                $lines[] = self::line('order', $orderNumber);
            }

            $buttons = $orderId !== null
                ? TelegramMessage::adminButton(route('admin.orders.show', $orderId, absolute: false), __('telegram.lead.open_in_admin'))
                : TelegramMessage::adminButton(route('admin.settings.integrations', absolute: false), __('telegram.order.open_settings'));

            return new TelegramMessage(implode("\n", $lines), $buttons);
        });
    }

    /**
     * @param  list<string>  $keys
     */
    public static function settingsChanged(array $keys, ?string $actor, string $locale): TelegramMessage
    {
        return self::inLocale($locale, function () use ($keys, $actor): TelegramMessage {
            $names = array_map(fn (string $key): string => IntegrationRegistry::has($key) ? IntegrationRegistry::field($key)->label() : $key, $keys);
            $lines = ['⚠️ <b>'.e(__('telegram.uzqr.settings_changed')).'</b>', self::line('fields', implode(', ', $names))];

            if ($actor !== null) {
                $lines[] = self::line('by', $actor);
            }

            $lines[] = e(__('telegram.uzqr.settings_check'));

            return new TelegramMessage(implode("\n", $lines), TelegramMessage::adminButton(route('admin.settings.integrations', absolute: false), __('telegram.order.open_settings')));
        });
    }

    private static function marker(bool $test): string
    {
        return $test ? '🧪 <b>TEST</b> · ' : '';
    }

    private static function line(string $label, string $value): string
    {
        return '<b>'.e(__('telegram.uzqr.labels.'.$label)).':</b> '.e($value);
    }

    /**
     * @return list<array{text: string, url: string}>
     */
    private static function orderButton(Order $order): array
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
