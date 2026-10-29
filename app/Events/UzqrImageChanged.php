<?php

namespace App\Events;

use App\Events\Contracts\TelegramAlert;
use App\Models\User;
use App\Support\Telegram\OrderMessage;
use App\Support\Telegram\TelegramMessage;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The static UzQR code was uploaded, replaced or removed (security
 * condition 24) → finance + sales chats, so a swapped code is noticed.
 */
class UzqrImageChanged implements ShouldDispatchAfterCommit, TelegramAlert
{
    use Dispatchable, SerializesModels;

    /**
     * @param  string  $action  uploaded | replaced | removed
     */
    public function __construct(public string $action, public ?User $actor = null) {}

    public function telegramChats(): array
    {
        return ['finance', 'sales'];
    }

    public function telegramMessage(string $locale): ?TelegramMessage
    {
        return OrderMessage::uzqrChanged($this->action, $this->actor?->name, $locale);
    }
}
