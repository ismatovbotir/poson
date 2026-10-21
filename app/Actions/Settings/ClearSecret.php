<?php

namespace App\Actions\Settings;

use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Models\IntegrationSecret;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\Integrations\IntegrationRegistry;
use App\Support\Secrets;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Delete the admin value of one integration secret (a .env value, if any,
 * keeps working). Audited, Secrets cache flushed after commit.
 */
class ClearSecret
{
    /**
     * @return bool whether a stored value was deleted
     *
     * @throws InvalidArgumentException for an unknown or non-secret key
     */
    public function handle(string $key, ?User $user, ?string $ip): bool
    {
        if (! IntegrationRegistry::has($key) || ! IntegrationRegistry::field($key)->secret) {
            throw new InvalidArgumentException("Not an integration secret: {$key}");
        }

        return DB::transaction(function () use ($key, $user, $ip): bool {
            $secret = IntegrationSecret::query()->where('key', $key)->lockForUpdate()->first();

            if ($secret === null) {
                return false;
            }

            $secret->delete();
            SettingsAudit::record(SettingsArea::Integrations, $key, AuditAction::Cleared, $user, $ip);

            DB::afterCommit(fn () => Secrets::flush());

            return true;
        });
    }
}
