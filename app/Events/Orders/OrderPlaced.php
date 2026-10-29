<?php

namespace App\Events\Orders;

use App\Events\Contracts\TelegramAlert;
use App\Models\Order;
use App\Support\Telegram\OrderMessage;
use App\Support\Telegram\TelegramMessage;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An order was placed on the site or created by staff (after commit) →
 * sales chat.
 */
class OrderPlaced implements ShouldDispatchAfterCommit, TelegramAlert
{
    use Dispatchable, SerializesModels;

    public function __construct(public Order $order) {}

    public function telegramChats(): array
    {
        return ['sales'];
    }

    public function telegramMessage(string $locale): ?TelegramMessage
    {
        return OrderMessage::placed($this->order, $locale);
    }
}
