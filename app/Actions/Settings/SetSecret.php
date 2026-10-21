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
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Store (or replace) one integration secret, encrypted with APP_KEY.
 *
 * Validates the value with the field's rules (errors on "value", never
 * echoing it), then in one transaction saves it with its hint (last 4
 * characters for values of 20+ characters) and writes an audit row
 * (who/when/key/IP, never the value). The Secrets cache is flushed after
 * commit. A .env value still wins over the stored one.
 */
class SetSecret
{
    /**
     * @throws InvalidArgumentException for an unknown or non-secret key
     * @throws ValidationException
     */
    public function handle(string $key, #[\SensitiveParameter] string $value, ?User $user, ?string $ip, AuditAction $action = AuditAction::Updated): void
    {
        $field = IntegrationRegistry::has($key) ? IntegrationRegistry::field($key) : null;

        if ($field === null || ! $field->secret) {
            throw new InvalidArgumentException("Not an integration secret: {$key}");
        }

        $value = trim($value);

        $validator = Validator::make(['value' => $value], ['value' => ['required', ...$field->rules]], [
            'value.regex' => __('admin.settings.integrations.errors.secret_format'),
        ], ['value' => $field->label()]);

        if ($validator->fails()) {
            throw ValidationException::withMessages(['value' => $validator->errors()->get('value')]);
        }

        DB::transaction(function () use ($key, $value, $user, $ip, $action): void {
            IntegrationSecret::query()->updateOrCreate(['key' => $key], [
                'value' => $value,
                'hint' => IntegrationSecret::hintFor($value),
                'updated_by' => $user?->id,
            ]);

            SettingsAudit::record(SettingsArea::Integrations, $key, $action, $user, $ip);

            DB::afterCommit(fn () => Secrets::flush());
        });
    }
}
