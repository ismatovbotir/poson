<?php

namespace App\Livewire\Forms\Admin\Settings;

use App\Support\CompanySettings;
use App\Support\Site\AiBotRules;

/**
 * AI bots tab (plan 04 T12): allow or deny each AI crawler in robots.txt.
 * $bots holds bot key => allowed (toggle on = allow); presets fill it and
 * Save stores the full map under robots.ai_bots. Search engines are never
 * part of the form (always allowed).
 */
class AiBotsForm extends SettingsTabForm
{
    /** @var array<string, bool> */
    public array $bots = [];

    public function fillFrom(CompanySettings $settings): void
    {
        $this->bots = array_map(fn (string $rule): bool => $rule === AiBotRules::ALLOW, $settings->aiBots());
    }

    public function applyPreset(string $preset): void
    {
        if (! in_array($preset, AiBotRules::PRESETS, true)) {
            return;
        }

        $this->bots = array_map(fn (string $rule): bool => $rule === AiBotRules::ALLOW, AiBotRules::preset($preset));
    }

    /**
     * The preset the current toggles match, or null (custom).
     */
    public function currentPreset(): ?string
    {
        return AiBotRules::presetOf($this->map());
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'bots' => ['array'],
            'bots.*' => ['boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return [AiBotRules::SETTING_KEY => $this->map()];
    }

    /**
     * bot key => allow|deny over every editable bot (unknown keys ignored, missing = allow).
     *
     * @return array<string, string>
     */
    private function map(): array
    {
        $map = [];

        foreach (array_keys(AiBotRules::editable()) as $key) {
            $map[$key] = ($this->bots[$key] ?? true) === false ? AiBotRules::DENY : AiBotRules::ALLOW;
        }

        return $map;
    }
}
