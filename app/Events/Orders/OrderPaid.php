<?php

namespace App\Events\Orders;

use App\Models\OrderItem;

/**
 * The goods total was paid (manual mark-paid now; the webhooks in 11c/11d)
 * → finance + sales chats; mentions lines priced below their reference.
 */
class OrderPaid extends OrderPaymentEvent
{
    public function kind(): string
    {
        return 'paid';
    }

    public function telegramChats(): array
    {
        return ['finance', 'sales'];
    }

    protected function belowReference(): bool
    {
        return $this->order->items()->get()->contains(fn (OrderItem $item): bool => $item->isBelowReference() || ($item->price_edited && $item->product_id === null));
    }
}
