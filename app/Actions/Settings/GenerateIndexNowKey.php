<?php

namespace App\Actions\Settings;

use App\Enums\AuditAction;
use App\Models\User;

/**
 * Generate a new IndexNow key (plan 18): 32 random hex characters, stored
 * encrypted through SetSecret and audited as "generated" (never with the
 * value). The value is not returned; the old key URL stops answering at once.
 */
class GenerateIndexNowKey
{
    public const KEY = 'indexnow.key';

    public function __construct(protected SetSecret $setSecret) {}

    public function handle(?User $user, ?string $ip): void
    {
        $this->setSecret->handle(self::KEY, bin2hex(random_bytes(16)), $user, $ip, AuditAction::Generated);
    }
}
