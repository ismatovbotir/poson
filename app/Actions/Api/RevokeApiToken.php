<?php

namespace App\Actions\Api;

use App\Enums\ApiTokenType;
use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Models\ApiToken;
use App\Models\SettingsAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Revoke a price API token at once (plan 09). The row stays for the sync
 * log; a settings_audits row records who / when / IP. Revoking twice is a
 * no-op (returns false). Price API tokens only (MCP tokens: RevokeMcpToken).
 */
class RevokeApiToken
{
    public function handle(ApiToken $token, ?User $user, ?string $ip): bool
    {
        return DB::transaction(function () use ($token, $user, $ip): bool {
            $revoked = ApiToken::query()->whereKey($token->id)->ofType(ApiTokenType::PriceApi)->whereNull('revoked_at')->update(['revoked_at' => now()]) > 0;

            if ($revoked) {
                SettingsAudit::record(SettingsArea::ApiTokens, 'api_tokens.'.$token->id, AuditAction::Revoked, $user, $ip);
            }

            $token->refresh();

            return $revoked;
        });
    }
}
