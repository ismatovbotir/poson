<?php

namespace App\Events\Uzqr;

use App\Enums\PaymentMode;
use App\Events\Contracts\TelegramAlert;
use App\Services\Payments\Uzqr\UzqrAlerts;
use App\Support\Telegram\TelegramMessage;
use App\Support\Telegram\UzqrMessage;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A UzQR integration problem (auth failed / blocked, a QR still open at
 * the bank after expiry, an invalid bank answer…) → finance chat. Deduped
 * by UzqrAlerts (hourly per problem and mode). Never any key material.
 *
 * @see UzqrAlerts
 */
class UzqrIntegrationProblem implements ShouldDispatchAfterCommit, TelegramAlert
{
    use Dispatchable;

    public function __construct(public string $problem, public PaymentMode $mode, public ?string $orderNumber = null, public ?int $orderId = null) {}

    public function telegramChats(): array
    {
        return ['finance'];
    }

    public function telegramMessage(string $locale): ?TelegramMessage
    {
        return UzqrMessage::problem($this->problem, $this->mode, $this->orderNumber, $this->orderId, $locale);
    }
}
