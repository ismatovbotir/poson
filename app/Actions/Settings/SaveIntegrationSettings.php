<?php

namespace App\Actions\Settings;

use App\Enums\AuditAction;
use App\Enums\IntegrationSource;
use App\Enums\PaymeMode;
use App\Enums\SettingsArea;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\Integrations\IntegrationField;
use App\Support\Integrations\IntegrationRegistry;
use App\Support\Secrets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Save non-secret integration values (Settings → Integrations): chat IDs,
 * merchant IDs, Payme mode, AI switches, models and limits. Secrets go
 * through SetSecret / ClearSecret instead.
 *
 * validate() normalizes (trim, lowercase where the field asks) and checks
 * every value with its registry rules, plus: Payme can only switch to
 * "prod" when the merchant ID and the live key are configured. Fields
 * locked by a .env value are ignored (never written).
 *
 * handle() then, in one transaction, writes only the keys that changed
 * (compared with the stored row, or the registry default when there is
 * none; a null / '' value deletes the row, so the default applies again),
 * each with an audit row (never the value). The Secrets cache is flushed
 * after commit.
 */
class SaveIntegrationSettings
{
    /**
     * Normalized values to save, without the keys locked by .env.
     *
     * @param  array<string, mixed>  $values  registry key => new value
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException for an unknown or secret key
     * @throws ValidationException with errors keyed by registry key
     */
    public function validate(array $values): array
    {
        $normalized = [];
        $errors = [];

        foreach ($values as $key => $value) {
            $field = IntegrationRegistry::has($key) ? IntegrationRegistry::field($key) : null;

            if ($field === null || $field->secret) {
                throw new InvalidArgumentException("Not a non-secret integration field: {$key}");
            }

            if (Secrets::source($key) === IntegrationSource::Env) {
                continue;
            }

            $value = $this->normalize($field, $value);

            // Keys contain dots: validate each value on its own.
            $validator = Validator::make(['value' => $value], ['value' => ['nullable', ...$field->rules]], [], ['value' => $field->label()]);

            if ($validator->fails()) {
                $errors[$key] = $validator->errors()->get('value');

                continue;
            }

            $normalized[$key] = $value;
        }

        if (($normalized['payme.mode'] ?? null) === PaymeMode::Prod->value && ! $this->paymeLiveReady($normalized)) {
            $errors['payme.mode'] = [__('admin.settings.integrations.errors.payme_prod_requires_keys')];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $values  registry key => new value
     * @return list<string> the keys that changed
     *
     * @throws InvalidArgumentException for an unknown or secret key
     * @throws ValidationException with errors keyed by registry key
     */
    public function handle(array $values, ?User $user, ?string $ip): array
    {
        $normalized = $this->validate($values);

        if ($normalized === []) {
            return [];
        }

        return DB::transaction(function () use ($normalized, $user, $ip): array {
            $rows = Setting::query()->whereIn('key', array_keys($normalized))->lockForUpdate()->get()->keyBy('key');
            $changed = [];

            foreach ($normalized as $key => $value) {
                $field = IntegrationRegistry::field($key);
                $new = $field->cast($value);
                $row = $rows->get($key);

                // Unchanged: same as the stored row, or (without a row) the
                // default / empty, so defaults are never pinned as rows.
                if ($row !== null ? $new === $field->cast($row->value) : ($new === null || $new === $field->default)) {
                    continue;
                }

                if ($new === null) {
                    $row?->delete();
                    SettingsAudit::record(SettingsArea::Integrations, $key, AuditAction::Cleared, $user, $ip);
                } else {
                    Setting::query()->updateOrCreate(['key' => $key], ['group' => SettingsArea::Integrations->group(), 'value' => $new]);
                    SettingsAudit::record(SettingsArea::Integrations, $key, AuditAction::Updated, $user, $ip);
                }

                $changed[] = $key;
            }

            if ($changed !== []) {
                DB::afterCommit(fn () => Secrets::flush());
            }

            return $changed;
        });
    }

    protected function normalize(IntegrationField $field, mixed $value): mixed
    {
        if ($field->type === IntegrationField::TYPE_BOOL) {
            return (bool) $value;
        }

        if (! IntegrationField::isFilled($value) || ! is_scalar($value)) {
            return null;
        }

        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        return $field->lowercase ? strtolower($value) : $value;
    }

    /**
     * Live Payme needs the merchant ID (new value or the effective one) and the live key.
     *
     * @param  array<string, mixed>  $normalized
     */
    protected function paymeLiveReady(array $normalized): bool
    {
        $merchantId = array_key_exists('payme.merchant_id', $normalized)
            ? $normalized['payme.merchant_id']
            : Secrets::get('payme.merchant_id');

        return IntegrationField::isFilled($merchantId) && Secrets::isConfigured('payme.key');
    }
}
