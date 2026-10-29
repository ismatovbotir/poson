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
 * The company's bank requisites (account, bank, MFO, STIR) changed in
 * Settings → Company → Legal (security review M2): customers pay bank
 * transfers to them, so finance and sales are alerted. Only the names of
 * the changed fields are sent, never the values.
 */
class BankRequisitesChanged implements ShouldDispatchAfterCommit, TelegramAlert
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<string>  $fields  inn | bank | mfo | account
     */
    public function __construct(public array $fields, public ?User $actor = null) {}

    public function telegramChats(): array
    {
        return ['finance', 'sales'];
    }

    public function telegramMessage(string $locale): ?TelegramMessage
    {
        return OrderMessage::requisitesChanged($this->fields, $this->actor?->name, $locale);
    }
}
