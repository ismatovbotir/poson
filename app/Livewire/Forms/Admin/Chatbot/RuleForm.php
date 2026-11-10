<?php

namespace App\Livewire\Forms\Admin\Chatbot;

use App\Enums\ChatAction;
use App\Enums\KeywordMatch;
use App\Models\ChatRule;
use Livewire\Form;

/**
 * State of the chat rule form (plan 28 C2). Its shape mirrors the input of
 * SaveChatRule, which does the real validation (per-action params, plain
 * text, brand check, links, caps) and whose error keys therefore match the
 * property paths ("form.params.faq_id", "form.buttons.0.label.uz"). The
 * rules() here only keep the browser input to the expected types.
 */
class RuleForm extends Form
{
    public string $key = '';

    public string $name = '';

    public string $action = 'answer';

    /**
     * faq_id, topic, flow, target, slug, url, label{locale}, link_label{locale}.
     *
     * @var array<string, mixed>
     */
    public array $params = [];

    /** @var array<string, string> */
    public array $answer = ['uz' => '', 'ru' => '', 'en' => ''];

    /**
     * Each: label{locale}, kind (rule | site | url), rule_id, target, slug, url.
     *
     * @var list<array<string, mixed>>
     */
    public array $buttons = [];

    /**
     * Each: phrase, locale ('' = any), match_type, weight.
     *
     * @var list<array<string, mixed>>
     */
    public array $keywords = [];

    public bool $on_web = true;

    public bool $on_telegram = true;

    public bool $in_menu = false;

    public int $menu_sort = 0;

    public int $priority = 0;

    public bool $is_active = true;

    public function setRule(ChatRule $rule): void
    {
        $params = is_array($rule->params) ? $rule->params : [];

        $this->key = $rule->key;
        $this->name = $rule->name;
        $this->action = $rule->action->value;
        $this->params = [
            ...$params,
            'label' => $this->locales($params['label'] ?? []),
            'link_label' => $this->locales($params['link_label'] ?? []),
            'target' => $params['target'] ?? '',
            'slug' => $params['slug'] ?? '',
            'url' => $params['url'] ?? '',
            'faq_id' => isset($params['faq_id']) ? (string) $params['faq_id'] : '',
            'topic' => $params['topic'] ?? '',
            'flow' => $params['flow'] ?? '',
        ];
        $this->answer = $this->locales($rule->getTranslations('answer'));
        $this->buttons = array_values(array_map(fn (array $button): array => [
            'label' => $this->locales($button['label'] ?? []),
            'kind' => isset($button['rule_id']) ? 'rule' : (isset($button['url']) ? 'url' : 'site'),
            'rule_id' => isset($button['rule_id']) ? (string) $button['rule_id'] : '',
            'target' => $button['target'] ?? '',
            'slug' => $button['slug'] ?? '',
            'url' => $button['url'] ?? '',
        ], (array) $rule->buttons));
        $this->keywords = $rule->keywords->sortBy('id')->map(fn ($keyword): array => [
            'phrase' => $keyword->phrase,
            'locale' => $keyword->locale ?? '',
            'match_type' => $keyword->match_type->value,
            'weight' => $keyword->weight,
        ])->values()->all();
        $this->on_web = $rule->on_web;
        $this->on_telegram = $rule->on_telegram;
        $this->in_menu = $rule->in_menu;
        $this->menu_sort = $rule->menu_sort;
        $this->priority = $rule->priority;
        $this->is_active = $rule->is_active;
    }

    /**
     * Blank values for a new rule.
     */
    public function blank(): void
    {
        $this->params = [
            'label' => $this->locales([]), 'link_label' => $this->locales([]),
            'target' => '', 'slug' => '', 'url' => '', 'faq_id' => '', 'topic' => '', 'flow' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'key' => ['nullable', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:120'],
            'action' => ['required', 'string', 'in:'.implode(',', array_column(ChatAction::cases(), 'value'))],
            'params' => ['array'],
            'params.*' => ['nullable'],
            'answer' => ['array'],
            'answer.*' => ['nullable', 'string', 'max:2000'],
            'buttons' => ['array', 'max:'.(int) config('chatbot.max_buttons', 8)],
            'buttons.*.kind' => ['required', 'in:rule,site,url'],
            'keywords' => ['array', 'max:'.(int) config('chatbot.max_keywords_per_rule', 50)],
            'keywords.*.phrase' => ['required', 'string', 'max:'.(int) config('chatbot.keyword_max', 120)],
            'keywords.*.locale' => ['nullable', 'in:uz,ru,en'],
            'keywords.*.match_type' => ['required', 'in:'.implode(',', array_column(KeywordMatch::cases(), 'value'))],
            'keywords.*.weight' => ['required', 'integer', 'min:1', 'max:100'],
            'menu_sort' => ['integer', 'min:0', 'max:32000'],
            'priority' => ['integer', 'min:-1000', 'max:1000'],
        ];
    }

    /**
     * The input of SaveChatRule.
     *
     * @return array<string, mixed>
     */
    public function ruleData(): array
    {
        $params = $this->params;
        $buttons = [];

        foreach ($this->buttons as $button) {
            $entry = ['label' => $button['label'] ?? []];

            match ($button['kind'] ?? 'site') {
                'rule' => $entry['rule_id'] = $button['rule_id'] ?? '',
                'url' => $entry['url'] = $button['url'] ?? '',
                default => $entry += ['target' => $button['target'] ?? '', 'slug' => $button['slug'] ?? ''],
            };

            $buttons[] = $entry;
        }

        return [
            'key' => $this->key,
            'name' => $this->name,
            'action' => $this->action,
            'params' => $params,
            'answer' => $this->answer,
            'buttons' => $buttons,
            'keywords' => array_map(fn (array $keyword): array => [
                'phrase' => $keyword['phrase'] ?? '',
                'locale' => $keyword['locale'] ?? '',
                'match_type' => $keyword['match_type'] ?? 'word',
                'weight' => $keyword['weight'] ?? 10,
            ], $this->keywords),
            'on_web' => $this->on_web,
            'on_telegram' => $this->on_telegram,
            'in_menu' => $this->in_menu,
            'menu_sort' => $this->menu_sort,
            'priority' => $this->priority,
            'is_active' => $this->is_active,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function locales(mixed $values): array
    {
        $values = is_array($values) ? $values : [];
        $out = [];

        foreach (config('app.supported_locales') as $locale) {
            $out[$locale] = is_string($values[$locale] ?? null) ? $values[$locale] : '';
        }

        return $out;
    }
}
