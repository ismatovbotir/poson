<?php

namespace App\Actions\Chat;

use App\Actions\Chat\Concerns\GuardsStatusLookup;
use App\Models\Order;
use App\Models\TelegramUser;
use App\Support\Chat\ChatReply;
use App\Support\Chat\LookupNumber;
use Illuminate\Database\Eloquent\Builder;

/**
 * Order status in the Telegram bot (plan 28 C3, CB-15, CB-28, CB-29, CB-30).
 * Called by HandleChatInput with the locked telegram_users row; never on the
 * website and never from the test console.
 *
 * After the gate and limits (GuardsStatusLookup) exactly one orders query,
 * always with the customer's verified phone: number (or the id of a button
 * from the customer's own list) AND phone AND is_test = 0 AND created in the
 * last chatbot.lookup.order_months (12) months. Never by number first, no
 * customer or contact phone expansion. Every miss gets the same reply.
 *
 * Labels are the OrderStatus / PaymentStatus enum labels in the customer's
 * language. The reply holds only the status label, the day of the last status change
 * (Asia/Tashkent) and the payment status label: no name, address, amounts,
 * items or /o link.
 */
class LookupOrderStatus
{
    use GuardsStatusLookup;

    /**
     * Columns read: nothing else of an order ever reaches the reply.
     */
    public const COLUMNS = ['id', 'number', 'status', 'payment_status', 'status_changed_at'];

    /**
     * A typed order number ("O-2026-0001").
     */
    public function byNumber(TelegramUser $user, string $number, string $locale): ChatReply
    {
        return $this->lookup($user, $locale, fn (Builder $query) => $query->where('number', $number), $number);
    }

    /**
     * A button of the customer's own list (the id comes from bot_dialog, and is checked again here).
     */
    public function byId(TelegramUser $user, int $id, string $locale): ChatReply
    {
        return $this->lookup($user, $locale, fn (Builder $query) => $query->whereKey($id));
    }

    /**
     * The "Order status" button (CB-29): the customer's last orders as buttons.
     */
    public function recent(TelegramUser $user, string $locale): ChatReply
    {
        $phone = $this->verifiedPhone($user);

        if ($phone === null) {
            return $this->managerOffer($locale);
        }

        if (! $this->allowLookup($user)) {
            return $this->limited($locale);
        }

        $orders = $this->scoped($phone)
            ->latest('id')
            ->limit(max(1, min(5, (int) config('chatbot.lookup.recent', 5))))
            ->get(self::COLUMNS);

        if ($orders->isEmpty()) {
            return new ChatReply(__('chatbot.lookup.order.none', [], $locale), $this->closingButtons($locale), null, ChatReply::DONE);
        }

        $buttons = $orders->map(fn (Order $order): array => [
            'type' => 'order',
            'label' => $order->number.' · '.__('enums.order_status.'.$order->status->value, [], $locale),
            'id' => $order->id,
        ])->all();

        return new ChatReply(__('chatbot.lookup.order.recent', [], $locale), [...array_values($buttons), ...$this->closingButtons($locale)], null, ChatReply::DONE);
    }

    protected function kind(): string
    {
        return LookupNumber::ORDER;
    }

    /**
     * @param  callable(Builder<Order>): mixed  $match
     */
    private function lookup(TelegramUser $user, string $locale, callable $match, ?string $number = null): ChatReply
    {
        $phone = $this->verifiedPhone($user);

        if ($phone === null) {
            return $this->managerOffer($locale);
        }

        if (! $this->allowLookup($user)) {
            return $this->limited($locale);
        }

        $query = $this->scoped($phone);
        $match($query);
        $order = $query->first(self::COLUMNS);

        if (! $order instanceof Order) {
            $this->recordFailure($number);

            return $this->notFound($locale);
        }

        $this->recordSuccess();

        $lines = [
            __('chatbot.lookup.order.title', ['number' => $order->number], $locale),
            __('chatbot.lookup.status', ['status' => __('enums.order_status.'.$order->status->value, [], $locale)], $locale),
        ];

        $day = $this->day($order->status_changed_at, $locale);

        if ($day !== null) {
            $lines[] = __('chatbot.lookup.since', ['day' => $day], $locale);
        }

        $lines[] = __('chatbot.lookup.order.payment', ['status' => __('enums.payment_status.'.$order->payment_status->value, [], $locale)], $locale);

        return new ChatReply(implode("\n", $lines), $this->closingButtons($locale), null, ChatReply::DONE);
    }

    /**
     * @return Builder<Order>
     */
    private function scoped(string $phone): Builder
    {
        return Order::query()
            ->where('phone', $phone)
            ->where('is_test', false)
            ->where('created_at', '>=', now()->subMonths(max(1, (int) config('chatbot.lookup.order_months', 12))));
    }
}
