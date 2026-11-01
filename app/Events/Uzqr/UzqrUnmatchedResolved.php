<?php

namespace App\Events\Uzqr;

use App\Events\Contracts\TelegramAlert;
use App\Models\User;
use App\Models\UzqrTransaction;
use App\Support\Telegram\TelegramMessage;
use App\Support\Telegram\UzqrMessage;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An Admin resolved an unmatched UzQR payment (condition 50) → finance + sales.
 */
class UzqrUnmatchedResolved implements ShouldDispatchAfterCommit, TelegramAlert
{
    use Dispatchable, SerializesModels;

    public function __construct(public UzqrTransaction $transaction, public ?User $actor = null) {}

    public function telegramChats(): array
    {
        return ['finance', 'sales'];
    }

    public function telegramMessage(string $locale): ?TelegramMessage
    {
        return UzqrMessage::resolved($this->transaction, $this->actor?->name, $locale);
    }
}
