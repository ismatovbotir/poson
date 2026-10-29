<?php

namespace App\Events\Orders;

use App\Events\Contracts\TelegramAlert;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use App\Support\Telegram\OrderMessage;
use App\Support\Telegram\TelegramMessage;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Base of the payment ledger events (after commit, Telegram alert). The
 * subclass names the message kind and the chats.
 */
abstract class OrderPaymentEvent implements ShouldDispatchAfterCommit, TelegramAlert
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Order $order,
        public OrderPayment $payment,
        public ?User $actor = null,
    ) {}

    abstract public function kind(): string;

    public function telegramMessage(string $locale): ?TelegramMessage
    {
        return OrderMessage::payment($this->kind(), $this->order, $this->payment, $this->actor?->name, $locale, $this->belowReference());
    }

    /**
     * Mention lines priced below their reference (security condition 26).
     */
    protected function belowReference(): bool
    {
        return false;
    }
}
