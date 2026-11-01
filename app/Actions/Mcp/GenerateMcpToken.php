<?php

namespace App\Actions\Mcp;

use App\Enums\ApiTokenType;
use App\Enums\AuditAction;
use App\Enums\McpRevokeReason;
use App\Enums\SettingsArea;
use App\Models\ApiToken;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\Mcp\IpAllowList;
use App\Support\Mcp\McpAlerts;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Generate an MCP token for the holder's device (plan 13 C3/C4/C9/C14/C26).
 *
 * - The holder must be the single MCP holder and an active Admin (checked
 *   under a lock); grant access first (GrantMcpAccess).
 * - At most MAX_ACTIVE active tokens, each with a required label; a label
 *   that already has an active token is regenerated (the old one is
 *   revoked, reason regenerated).
 * - "posm_" + 48 random characters; only the sha256 and a 12-character
 *   prefix are stored. No expiry (decision 2026-11-01); idle auto-revoke
 *   after idle_revoke_days (14–180, default 45), optional IP allow-list.
 * - In production it refuses while the Telegram bot or the management chat
 *   is not configured (C26): token alerts must reach someone.
 * Audited (never the value) and alerted. Returns the token and its plain
 * value (show once).
 */
class GenerateMcpToken
{
    public const MAX_ACTIVE = 3;

    /**
     * @param  array{label?: mixed, idle_revoke_days?: mixed, allowed_ips?: mixed}  $data
     * @return array{0: ApiToken, 1: string}
     *
     * @throws ValidationException
     */
    public function handle(User $holder, array $data, User $by, ?string $ip): array
    {
        if (app()->isProduction() && ! McpAlerts::isConfigured()) {
            throw ValidationException::withMessages(['label' => __('mcp.errors.management_chat_missing')]);
        }

        $values = static::validate($data);

        $plain = ApiToken::generatePlainText(ApiTokenType::Mcp);

        [$token, $regenerated] = DB::transaction(function () use ($holder, $values, $plain, $by, $ip): array {
            $holder = User::query()->whereKey($holder->id)->lockForUpdate()->firstOrFail();

            if (! $holder->isActiveAdmin() || ! $holder->hasMcpAccess()) {
                throw ValidationException::withMessages(['label' => __('mcp.errors.not_holder')]);
            }

            if (User::query()->whereNotNull('mcp_access_granted_at')->whereKeyNot($holder->id)->exists()) {
                throw ValidationException::withMessages(['label' => __('mcp.errors.not_single_holder')]);
            }

            $active = ApiToken::query()->ofType(ApiTokenType::Mcp)->where('user_id', $holder->id)->whereNull('revoked_at')->lockForUpdate()->get();
            $sameLabel = $active->first(fn (ApiToken $token): bool => mb_strtolower($token->name) === mb_strtolower($values['label']));

            if ($sameLabel === null && $active->count() >= self::MAX_ACTIVE) {
                throw ValidationException::withMessages(['label' => __('mcp.errors.too_many_tokens', ['max' => self::MAX_ACTIVE])]);
            }

            if ($sameLabel !== null) {
                app(RevokeMcpToken::class)->handle($sameLabel, McpRevokeReason::Regenerated, $by, $ip, alert: false);
            }

            $token = ApiToken::query()->create([
                'type' => ApiTokenType::Mcp,
                'user_id' => $holder->id,
                'name' => $values['label'],
                'token_hash' => ApiToken::hash($plain),
                'token_prefix' => substr($plain, 0, ApiToken::DISPLAY_PREFIX_LENGTH),
                'abilities' => [ApiToken::ABILITY_MCP],
                'allowed_ips' => $values['allowed_ips'] === [] ? null : $values['allowed_ips'],
                'idle_revoke_days' => $values['idle_revoke_days'],
                'created_by' => $by->id,
                'expires_at' => null,
            ]);

            SettingsAudit::record(SettingsArea::Mcp, 'mcp_tokens.'.$token->id, AuditAction::Generated, $by, $ip);

            McpAlerts::tokenGenerated($token, $holder, $by, $sameLabel !== null);

            return [$token, $sameLabel !== null];
        });

        return [$token, $plain];
    }

    /**
     * Label, idle days and allow-list of a token (shared with UpdateMcpTokenSettings).
     *
     * @param  array<string, mixed>  $data
     * @return array{label: string, idle_revoke_days: int|null, allowed_ips: list<string>}
     *
     * @throws ValidationException
     */
    public static function validate(array $data, bool $withLabel = true): array
    {
        $input = [
            'label' => trim((string) ($data['label'] ?? '')),
            'idle_revoke_days' => ($data['idle_revoke_days'] ?? null) === '' ? null : ($data['idle_revoke_days'] ?? null),
            'allowed_ips' => IpAllowList::parse($data['allowed_ips'] ?? null),
        ];

        $rules = [
            'idle_revoke_days' => ['nullable', 'integer', 'between:'.ApiToken::MIN_IDLE_REVOKE_DAYS.','.ApiToken::MAX_IDLE_REVOKE_DAYS],
            'allowed_ips' => ['array', 'max:'.IpAllowList::MAX],
            'allowed_ips.*' => ['string', 'max:64', function (string $attribute, mixed $value, Closure $fail): void {
                if (! IpAllowList::isValidEntry((string) $value)) {
                    $fail(__('mcp.errors.invalid_ip', ['value' => mb_substr((string) $value, 0, 64)]));
                }
            }],
        ];

        if ($withLabel) {
            $rules['label'] = ['required', 'string', 'max:100'];
        }

        Validator::make($input, $rules, [], [
            'label' => __('mcp.tokens.fields.label'),
            'idle_revoke_days' => __('mcp.tokens.fields.idle_revoke_days'),
            'allowed_ips' => __('mcp.tokens.fields.allowed_ips'),
        ])->validate();

        return [
            'label' => $input['label'],
            'idle_revoke_days' => $input['idle_revoke_days'] === null ? null : (int) $input['idle_revoke_days'],
            'allowed_ips' => $input['allowed_ips'],
        ];
    }
}
