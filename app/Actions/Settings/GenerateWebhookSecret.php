<?php

namespace App\Actions\Settings;

use App\Enums\AuditAction;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Generate a new Telegram webhook secret (the secret_token Telegram sends
 * in X-Telegram-Bot-Api-Secret-Token): 48 random letters and digits, stored
 * encrypted through SetSecret and audited as "generated". The value is not
 * returned. The old webhook stops validating at once: after this commits,
 * the Integrations screen calls ConfigureTelegramWebhook::afterSecretGenerated()
 * (plan 14 v2, condition 1), which registers the webhook with the new secret.
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
