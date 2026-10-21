<?php

namespace App\Actions\Settings;

use App\Enums\AuditAction;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Generate a new Telegram webhook secret (the secret_token Telegram sends
 * in X-Telegram-Bot-Api-Secret-Token): 48 random letters and digits, stored
 * encrypted through SetSecret and audited as "generated". The value is not
 * returned: the bot module passes it to setWebhook itself, and the old
 * webhook stops validating until setWebhook runs again.
 */
class GenerateWebhookSecret
{
    public const KEY = 'telegram.webhook_secret';

    public const LENGTH = 48;

    public function __construct(protected SetSecret $setSecret) {}

    public function handle(?User $user, ?string $ip): void
    {
        $this->setSecret->handle(self::KEY, Str::random(self::LENGTH), $user, $ip, AuditAction::Generated);
    }
}
