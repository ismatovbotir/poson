<?php

namespace App\Events\Uzqr;

use App\Events\Contracts\TelegramAlert;
use App\Models\UzqrTransaction;
use App\Support\Telegram\TelegramMessage;
use App\Support\Telegram\UzqrMessage;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A UzQR payment arrived that the order could not take (condition 50):
 * money received, the order is untouched, an Admin must resolve it →
 * finance + sales chats (no payer data, no pay_id).
 */
class UzqrPaymentUnmatched implements ShouldDispatchAfterCommit, TelegramAlert
{
    use Dispatchable, SerializesModels;

    public function __construct(public UzqrTransaction $transaction) {}

    public function telegramChats(): array
    {
        return ['finance', 'sales'];
    }

    public function telegramMessage(string $locale): ?TelegramMessage
    {
        return UzqrMessage::unmatched($this->transaction, $locale);
    }
}
