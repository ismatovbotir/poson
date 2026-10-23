<?php

namespace App\Livewire\Admin\Concerns;

use App\Livewire\Admin\Ai\Assistant;
use App\Services\Ai\AiAssist;
use App\Support\Ai\AiContext;
use App\Support\Ai\FieldRegistry;
use Livewire\Attributes\On;

/**
 * AI assist for a form component (plan 02 §2). The form declares which
 * properties the assistant may fill (aiTargets(): "form.name" =>
 * "category.name") and builds the record context from its unsaved state
 * (aiContext()). <x-admin.ai-button field="…" target="…"> calls openAi();
 * the Assistant answers with the "ai-apply" event, applied only when it is
 * addressed to this component and the target is whitelisted.
 */
trait WithAiAssist
{
    /**
     * Whitelist: form property (an array keyed by locale) => FieldRegistry key.
     *
     * @return array<string, string>
     */
    abstract protected function aiTargets(): array;

    /**
     * Context of the record being edited, from the unsaved form state.
     */
    abstract protected function aiContext(string $fieldKey): AiContext;

    /**
     * Open the assistant for a field (the button passes the active locale tab).
     */
    public function openAi(string $target, string $locale = 'uz'): void
    {
        $targets = $this->aiTargets();

        if (! isset($targets[$target]) || ! FieldRegistry::has($targets[$target]) || ! AiAssist::availableFor(auth()->user())) {
            return;
        }

        $fieldKey = $targets[$target];

        $this->dispatch(
            'ai-open',
            owner: $this->getId(),
            target: $target,
            field: $fieldKey,
            locale: in_array($locale, config('app.supported_locales'), true) ? $locale : config('app.supported_locales')[0],
            context: $this->aiContext($fieldKey)->toArray(),
            current: $this->aiCurrentValues($target),
        )->to(Assistant::class);
    }

    /**
     * Put the accepted texts into the form (not saved: the manager saves the
     * form as usual). Ignored for another component, an unknown target or
     * a target whose registered field isn't the one the text was written for.
     *
     * @param  array<string, mixed>  $values  locale => text
     */
    #[On('ai-apply')]
    public function applyAi(string $owner, string $target, string $field, array $values): void
    {
        if ($owner !== $this->getId() || ($this->aiTargets()[$target] ?? null) !== $field) {
            return;
        }

        foreach (config('app.supported_locales') as $locale) {
            if (isset($values[$locale]) && is_string($values[$locale]) && $values[$locale] !== '') {
                data_set($this, "{$target}.{$locale}", $values[$locale]);
            }
        }
    }

    /**
     * Current (unsaved) values of a target per locale.
     *
     * @return array<string, string>
     */
    protected function aiCurrentValues(string $target): array
    {
        $values = data_get($this, $target);
        $current = [];

        foreach (config('app.supported_locales') as $locale) {
            $value = is_array($values) ? ($values[$locale] ?? '') : '';
            $current[$locale] = is_string($value) ? $value : '';
        }

        return $current;
    }
}
