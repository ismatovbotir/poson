<?php

namespace App\Data\Orders;

use App\Enums\OrderSource;
use App\Models\TelegramUser;
use InvalidArgumentException;

/**
 * Who places an order through PlaceOrder (plan 20 §6.1): the site checkout
 * (default) or the Telegram Mini App with the signed-in Telegram user.
 * Carries no payment rules: for source Telegram PlaceOrder computes the
 * allowed methods itself (PaymentOptions::forMiniApp(), MA-8) and checks
 * that the cart belongs to this user (MA-21).
 */
final readonly class OrderOrigin
{
    public function __construct(
        public OrderSource $source = OrderSource::Site,
        public ?int $telegramUserId = null,
    ) {
        if (($source === OrderSource::Telegram) !== ($telegramUserId !== null)) {
            throw new InvalidArgumentException('A Telegram order origin needs exactly its Telegram user.');
        }

        if (! in_array($source, [OrderSource::Site, OrderSource::Telegram], true)) {
            throw new InvalidArgumentException('PlaceOrder only places site and Telegram orders.');
        }
    }

    public static function site(): self
    {
        return new self;
    }

    public static function telegram(TelegramUser $user): self
    {
        return new self(OrderSource::Telegram, $user->id);
    }

    public function isTelegram(): bool
    {
        return $this->source === OrderSource::Telegram;
    }
}
