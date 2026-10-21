<?php

namespace App\Livewire\Forms\Admin\Settings;

use App\Enums\IntegrationSource;
use App\Support\Integrations\IntegrationField;
use App\Support\Integrations\IntegrationRegistry;
use App\Support\Secrets;
use Livewire\Form;

/**
 * Non-secret integration values of Settings → Integrations, nested by
 * registry key ("telegram.chats.sales" → values.telegram.chats.sales).
 * Secrets never enter this form. Validation uses the registry rules inside
 * SaveIntegrationSettings; its errors are mapped to "form.values.<key>".
 */
class IntegrationSettingsForm extends Form
{
    /**
     * @var array<string, mixed>
     */
    public array $values = [];

    /**
     * Load the values saved in admin (else the defaults). Fields locked by
     * .env show their effective value and are never sent back.
     */
    public function fillFrom(): void
    {
        $values = [];

        foreach (IntegrationRegistry::settings() as $key => $field) {
            $value = Secrets::source($key) === IntegrationSource::Env
                ? Secrets::get($key)
                : (Secrets::storedSetting($key) ?? $field->default);

            data_set($values, $key, match ($field->type) {
                IntegrationField::TYPE_BOOL => (bool) $value,
                default => $value === null ? '' : (string) $value,
            });
        }

        $this->values = $values;
    }

    /**
     * Values of one section to save, registry key => value, without the
     * fields locked by .env.
     *
     * @return array<string, mixed>
     */
    public function valuesFor(string $group): array
    {
        $values = [];

        foreach (IntegrationRegistry::group($group) as $key => $field) {
            if ($field->secret || Secrets::source($key) === IntegrationSource::Env) {
                continue;
            }

            $values[$key] = data_get($this->values, $key);
        }

        return $values;
    }
}
