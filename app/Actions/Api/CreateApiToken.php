<?php

namespace App\Actions\Api;

use App\Enums\ApiTokenType;
use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Models\ApiToken;
use App\Models\SettingsAudit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Create a price API token (Settings → API tokens, plan 09).
 *
 * The plain token (ApiToken::PREFIX + 48 random characters) is returned
 * once and never stored: only its sha256 and a 12-character prefix. One
 * transaction with a settings_audits row (who / when / IP, never the value).
 */
class CreateApiToken
{
    /**
     * Expiry choices in days (null = never).
     *
     * @var list<int|null>
     */
    public const EXPIRY_DAYS = [30, 90, 180, 365, null];

    /**
     * @param  array{name: string, expires_in_days: int|null}  $data
     * @return array{0: ApiToken, 1: string} The token and its plain value (show once)
     *
     * @throws ValidationException
     */
    public function handle(array $data, ?User $user, ?string $ip): array
    {
        $data['name'] = trim((string) ($data['name'] ?? ''));

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:100'],
            'expires_in_days' => ['nullable', 'integer', Rule::in(array_filter(self::EXPIRY_DAYS))],
        ], [], [
            'name' => __('api.tokens.fields.name'),
            'expires_in_days' => __('api.tokens.fields.expires'),
        ]);

        $validator->validate();

        $plain = ApiToken::generatePlainText(ApiTokenType::PriceApi);
        $days = $data['expires_in_days'] ?? null;

        $token = DB::transaction(function () use ($data, $plain, $days, $user, $ip): ApiToken {
            $token = ApiToken::query()->create([
                'type' => ApiTokenType::PriceApi,
                'name' => $data['name'],
                'token_hash' => ApiToken::hash($plain),
                'token_prefix' => substr($plain, 0, ApiToken::DISPLAY_PREFIX_LENGTH),
                'abilities' => [ApiToken::ABILITY_PRICES_WRITE],
                'created_by' => $user?->id,
                'expires_at' => $days !== null ? Carbon::now()->addDays((int) $days) : null,
            ]);

            SettingsAudit::record(SettingsArea::ApiTokens, 'api_tokens.'.$token->id, AuditAction::Created, $user, $ip);

            return $token;
        });

        return [$token, $plain];
    }
}
