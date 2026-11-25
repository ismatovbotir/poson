<?php

namespace App\Listeners;

use App\Actions\Telegram\QueueOrderStatusMessage;
use App\Enums\OrderStatus;
use App\Events\Orders\OrderPlaced;
use App\Events\Orders\OrderStatusChanged;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Mini App orders → a status message to the customer in the bot (plan 20
 * M5 §6.4, MA-14): "placed" from OrderPlaced, the rest from
 * OrderStatusChanged. Queued after commit on "default"; the work (filters,
 * insert-or-ignore, delivery job) is QueueOrderStatusMessage, so a retry of
 * this listener never sends twice. Registered by event discovery.
 */
class NotifyTelegramCustomer implements ShouldQueueAfterCommit
{
    use InteractsWithQueue;

    public string $queue = 'default';

    public bool $deleteWhenMissingModels = true;

    public function __construct(private readonly QueueOrderStatusMessage $queueMessage) {}

    /**
     * Only orders placed in the Mini App are queued at all.
     */
    public function shouldQueue(OrderPlaced|OrderStatusChanged $event): bool
    {
        return $event->order->telegram_user_id !== null && ! $event->order->is_test;
    }

    public function handle(OrderPlaced|OrderStatusChanged $event): void
    {
        $this->queueMessage->handle($event->order->id, $event instanceof OrderStatusChanged ? $event->to : OrderStatus::New);
    }
}
