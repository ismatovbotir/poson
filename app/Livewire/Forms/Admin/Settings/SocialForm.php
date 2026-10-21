<?php

namespace App\Livewire\Forms\Admin\Settings;

use App\Enums\SocialNetwork;
use App\Rules\SocialUrl;
use App\Support\CompanySettings;
use Illuminate\Validation\Rule;

/**
 * Social tab: up to 10 links, each https on the network's own hosts
 * (no iframes, javascript:, http or look-alike domains).
 */
class SocialForm extends SettingsTabForm
{
    public const MAX_LINKS = 10;

    /** @var list<array{network: string, url: string}> */
    public array $links = [];

    public function fillFrom(CompanySettings $settings): void
    {
        $this->links = [];

        foreach ((array) $settings->get('social', []) as $link) {
            if (is_array($link)) {
                $this->links[] = [
                    'network' => is_string($link['network'] ?? null) ? $link['network'] : '',
                    'url' => is_string($link['url'] ?? null) ? $link['url'] : '',
                ];
            }
        }
    }

    public function addLink(): void
    {
        if (count($this->links) < self::MAX_LINKS) {
            $this->links[] = ['network' => SocialNetwork::Instagram->value, 'url' => ''];
        }
    }

    public function removeLink(int $index): void
    {
        if (isset($this->links[$index])) {
            array_splice($this->links, $index, 1);
        }
    }

    public function normalize(): void
    {
        foreach ($this->links as $index => $link) {
            if (is_array($link) && is_scalar($link['url'] ?? null)) {
                $this->links[$index]['url'] = trim((string) $link['url']);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $rules = [
            'links' => ['array', 'max:'.self::MAX_LINKS],
            'links.*.network' => ['required', Rule::enum(SocialNetwork::class)],
        ];

        foreach ($this->links as $index => $link) {
            $network = is_array($link) && is_string($link['network'] ?? null) ? SocialNetwork::tryFrom($link['network']) : null;
            $rules["links.{$index}.url"] = ['required', 'string', 'max:255', new SocialUrl($network)];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        $attributes = ['links' => __('admin.settings.company.tabs.social')];

        foreach (array_keys($this->links) as $index) {
            $attributes["links.{$index}.network"] = __('admin.settings.company.fields.network');
            $attributes["links.{$index}.url"] = __('admin.settings.company.fields.url');
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return [
            'social' => array_map(fn (array $link): array => ['network' => (string) $link['network'], 'url' => (string) $link['url']], array_values($this->links)),
        ];
    }
}
