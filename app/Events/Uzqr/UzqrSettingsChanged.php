<?php

namespace App\Events\Uzqr;

use App\Events\Contracts\TelegramAlert;
use App\Models\User;
use App\Support\Telegram\TelegramMessage;
use App\Support\Telegram\UzqrMessage;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * UzQR integration settings changed (INN, keys, mode; decision "11b review":
 * step-up + alerts) → finance + sales chats, key names only, never values.
 */
class UzqrSettingsChanged implements ShouldDispatchAfterCommit, TelegramAlert
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<string>  $keys
     */
    public function __construct(public array $keys, public ?User $actor = null) {}

    public function telegramChats(): array
    {
        return ['finance', 'sales'];
    }

    public function telegramMessage(string $locale): ?TelegramMessage
    {
        return UzqrMessage::settingsChanged($this->keys, $this->actor?->name, $locale);
    }
}
