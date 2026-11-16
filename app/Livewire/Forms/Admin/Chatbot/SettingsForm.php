<?php

namespace App\Livewire\Forms\Admin\Chatbot;

use App\Enums\ChatChannel;
use App\Support\Chat\AiSwitch;
use App\Support\Chat\ChatbotSwitch;
use App\Support\Chat\ChatSettings;
use Livewire\Attributes\Locked;
use Livewire\Form;

/**
 * State of Chat bot → Settings (plan 28 C2). SaveChatbotSettings validates
 * and writes; the channel switches are kept out of data() unless the caller
 * is an Admin (the action refuses them for anyone else anyway). The AI
 * fields (plan 30) are sent only when they differ from the loaded state; the
 * action decides who may change them (AI-24, P2). The API key is never a
 * property here (AI-1).
 */
class SettingsForm extends Form
{
    public const AI_FIELDS = [
        'ai_telegram_enabled', 'ai_web_enabled', 'ai_translate_enabled', 'ai_daily_requests', 'ai_daily_tokens', 'ai_instructions',
        // Plan 32: the AI product wizard switch and its stage caps (Admin only).
        'ai_admin_enabled', 'ai_adm_vision_daily_requests', 'ai_adm_vision_daily_tokens', 'ai_adm_write_daily_requests', 'ai_adm_write_daily_tokens',
    ];

    /**
     * The daily cap fields (Plan 30 bot + Plan 32 wizard channels).
     */
    public const CAP_FIELDS = [
        'ai_daily_requests', 'ai_daily_tokens',
        'ai_adm_vision_daily_requests', 'ai_adm_vision_daily_tokens', 'ai_adm_write_daily_requests', 'ai_adm_write_daily_tokens',
    ];

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

    public bool $ai_telegram_enabled = false;

    public bool $ai_web_enabled = false;

    public bool $ai_translate_enabled = false;

    /**
     * Untyped on purpose: a non-numeric client value is a validation error, never a TypeError.
     *
     * @var int|string|null
     */
    public $ai_daily_requests = 0;

    /**
     * Untyped on purpose: a non-numeric client value is a validation error, never a TypeError.
     *
     * @var int|string|null
     */
    public $ai_daily_tokens = 0;

    public string $ai_instructions = '';

    public bool $ai_admin_enabled = false;

    /**
     * Untyped on purpose: a non-numeric client value is a validation error, never a TypeError.
     *
     * @var int|string|null
     */
    public $ai_adm_vision_daily_requests = 0;

    /**
     * Untyped on purpose: a non-numeric client value is a validation error, never a TypeError.
     *
     * @var int|string|null
     */
    public $ai_adm_vision_daily_tokens = 0;

    /**
     * Untyped on purpose: a non-numeric client value is a validation error, never a TypeError.
     *
     * @var int|string|null
     */
    public $ai_adm_write_daily_requests = 0;

    /**
     * Untyped on purpose: a non-numeric client value is a validation error, never a TypeError.
     *
     * @var int|string|null
     */
    public $ai_adm_write_daily_tokens = 0;

    /**
     * The AI values shown when the form was loaded (only changed ones are sent).
     *
     * @var array<string, bool|int|string>
     */
    #[Locked]
    public array $aiLoaded = [];

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

        // The stored state (what an Admin saved), so a switch paused by the privacy gate can still be cleared.
        $this->web_enabled = ChatbotSwitch::isStored(ChatChannel::Web);
        $this->telegram_enabled = ChatbotSwitch::isStored(ChatChannel::Telegram);
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

        $this->ai_telegram_enabled = AiSwitch::isStored(ChatChannel::Telegram);
        $this->ai_web_enabled = AiSwitch::isStored(ChatChannel::Web);
        $this->ai_translate_enabled = $settings['ai']['translate_enabled'];
        $this->ai_daily_requests = $settings['ai']['daily_requests'];
        $this->ai_daily_tokens = $settings['ai']['daily_tokens'];
        $this->ai_instructions = $settings['ai']['instructions'];
        $this->ai_admin_enabled = $settings['ai']['admin_enabled'];

        foreach (['adm_vision', 'adm_write'] as $channel) {
            $this->{"ai_{$channel}_daily_requests"} = $settings['ai']['caps'][$channel]['requests'] ?? 0;
            $this->{"ai_{$channel}_daily_tokens"} = $settings['ai']['caps'][$channel]['tokens'] ?? 0;
        }
        $this->aiLoaded = [];

        foreach (self::AI_FIELDS as $field) {
            $this->aiLoaded[$field] = $this->{$field};
        }
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
            'ai_telegram_enabled' => ['boolean'],
            'ai_web_enabled' => ['boolean'],
            'ai_translate_enabled' => ['boolean'],
            'ai_daily_requests' => ['required', 'integer', 'min:0', 'max:'.(int) config('chatbot.ai.caps.requests_max')],
            'ai_daily_tokens' => ['required', 'integer', 'min:0', 'max:'.(int) config('chatbot.ai.caps.tokens_max')],
            'ai_instructions' => ['nullable', 'string', 'max:'.(int) config('chatbot.ai.instructions_max', 2000)],
            'ai_admin_enabled' => ['boolean'],
            'ai_adm_vision_daily_requests' => ['required', 'integer', 'min:0', 'max:'.(int) config('chatbot.ai.caps.requests_max')],
            'ai_adm_vision_daily_tokens' => ['required', 'integer', 'min:0', 'max:'.(int) config('chatbot.ai.caps.tokens_max')],
            'ai_adm_write_daily_requests' => ['required', 'integer', 'min:0', 'max:'.(int) config('chatbot.ai.caps.requests_max')],
            'ai_adm_write_daily_tokens' => ['required', 'integer', 'min:0', 'max:'.(int) config('chatbot.ai.caps.tokens_max')],
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
        unset($data['loaded'], $data['aiLoaded']);

        // Validated integers (the untyped cap fields may hold "100" from the client).
        foreach (self::CAP_FIELDS as $field) {
            if (is_numeric($data[$field] ?? null)) {
                $data[$field] = (int) $data[$field];
            }
        }

        foreach (self::AI_FIELDS as $field) {
            if (array_key_exists($field, $this->aiLoaded) && $data[$field] === $this->aiLoaded[$field]) {
                unset($data[$field]);
            }
        }

        foreach (['web_enabled', 'telegram_enabled'] as $field) {
            if (! $withSwitches || $data[$field] === ($this->loaded[$field] ?? false)) {
                unset($data[$field]);
            }
        }

        return $data;
    }
}
