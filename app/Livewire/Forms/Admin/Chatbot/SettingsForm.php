<?php

namespace App\Livewire\Forms\Admin\Chatbot;

use App\Enums\ChatChannel;
use App\Support\Chat\ChatbotSwitch;
use App\Support\Chat\ChatSettings;
use Livewire\Attributes\Locked;
use Livewire\Form;

/**
 * State of Chat bot → Settings (plan 28 C2). SaveChatbotSettings validates
 * and writes; the channel switches are kept out of data() unless the caller
 * is an Admin (the action refuses them for anyone else anyway).
 */
class SettingsForm extends Form
{
    public bool $web_enabled = false;

    public bool $telegram_enabled = false;

    /**
     * The switch states shown when the form was loaded: only a switch that
     * differs from it is sent, so a stale page cannot overwrite a change made
     * elsewhere meanwhile.
     *
     * @var array<string, bool>
     */
    #[Locked]
    public array $loaded = ['web_enabled' => false, 'telegram_enabled' => false];

    /** @var array<string, array<string, string>> */
    public array $texts = [];

    public bool $use_company_hours = true;

    /** @var array<string, array{open: bool, from: string, to: string}> */
    public array $hours = [];

    public string $handoff_target = 'sales';

    public int $handoff_ttl_hours = 8;

    public string $widget_position = 'right';

    public string $widget_pages = 'all';

    public bool $widget_mobile = true;

    public int $products_shown = 5;

    public function load(): void
    {
        $settings = ChatSettings::all();

        $this->web_enabled = ChatbotSwitch::isEnabled(ChatChannel::Web);
        $this->telegram_enabled = ChatbotSwitch::isEnabled(ChatChannel::Telegram);
        $this->loaded = ['web_enabled' => $this->web_enabled, 'telegram_enabled' => $this->telegram_enabled];
        $this->texts = $settings['texts'];
        $this->use_company_hours = $settings['use_company_hours'];
        $this->hours = $settings['hours'];
        $this->handoff_target = $settings['handoff_target'];
        $this->handoff_ttl_hours = $settings['handoff_ttl_hours'];
        $this->widget_position = $settings['widget_position'];
        $this->widget_pages = $settings['widget_pages'];
        $this->widget_mobile = $settings['widget_mobile'];
        $this->products_shown = $settings['products_shown'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'web_enabled' => ['boolean'],
            'telegram_enabled' => ['boolean'],
            'texts' => ['array'],
            'texts.*.*' => ['nullable', 'string', 'max:'.ChatSettings::TEXT_MAX],
            'use_company_hours' => ['boolean'],
            'hours.*.open' => ['boolean'],
            'hours.*.from' => ['required', 'string', 'max:5'],
            'hours.*.to' => ['required', 'string', 'max:5'],
            'handoff_target' => ['required', 'in:'.implode(',', ChatSettings::TARGETS)],
            'handoff_ttl_hours' => ['required', 'integer', 'min:1', 'max:72'],
            'widget_position' => ['required', 'in:'.implode(',', ChatSettings::POSITIONS)],
            'widget_pages' => ['required', 'in:'.implode(',', ChatSettings::PAGES)],
            'widget_mobile' => ['boolean'],
            'products_shown' => ['required', 'integer', 'min:3', 'max:8'],
        ];
    }

    /**
     * The input of SaveChatbotSettings; a switch only when the caller may change
     * switches and the user actually changed it since the form was loaded.
     *
     * @return array<string, mixed>
     */
    public function data(bool $withSwitches): array
    {
        $data = $this->all();
        unset($data['loaded']);

        foreach (['web_enabled', 'telegram_enabled'] as $field) {
            if (! $withSwitches || $data[$field] === ($this->loaded[$field] ?? false)) {
                unset($data[$field]);
            }
        }

        return $data;
    }
}
