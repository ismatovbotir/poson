<?php

namespace App\Enums;

/**
 * Steps of a chat lead dialog (plan 28 C4, CB-17), kept server-side in
 * telegram_users.bot_dialog: the customer writes the details, then checks
 * the summary and sends it.
 */
enum ChatDialogStep: string
{
    case Details = 'details';
    case Confirm = 'confirm';

    public function label(): string
    {
        return __("enums.chat_dialog_step.{$this->value}");
    }
}
