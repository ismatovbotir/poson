<?php

namespace App\Livewire\Admin\Settings\AiPrompts;

use App\Actions\Ai\ResetAiPromptOverride;
use App\Actions\Ai\SaveAiPromptOverride;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Models\AiPromptOverride;
use App\Support\Ai\FieldRegistry;
use App\Support\Ai\FieldSpec;
use App\Support\Ai\PromptTemplate;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Settings → AI prompts → one field (/admin/settings/ai-prompts/{fieldKey},
 * plan 12): the read-only lang default per locale, the prompt being edited
 * (the override, else the default in the UI language) with a /1200
 * counter, placeholder chips, a live preview filled with a sample record,
 * amber warnings (missing placeholder, brand-rule words), a red error for
 * unknown placeholders, Save and Reset. ai-prompts.manage (Admin only):
 * checked in boot() on every request and again before each write; the
 * actions authorize once more. An unregistered key is a 404.
 */
#[Layout('layouts::admin')]
class Edit extends Component
{
    use HandlesActionErrors;

    #[Locked]
    public string $fieldKey = '';

    public string $prompt = '';

    public function boot(): void
    {
        $this->authorize('viewAny', AiPromptOverride::class);
    }

    public function mount(string $fieldKey): void
    {
        abort_unless(FieldRegistry::has($fieldKey), 404);

        $this->fieldKey = $fieldKey;
        $this->prompt = FieldRegistry::get($fieldKey)->template();
    }

    public function save(SaveAiPromptOverride $saveOverride): void
    {
        $this->authorize('update', AiPromptOverride::class);

        $override = $this->runFormAction(fn (): ?AiPromptOverride => $saveOverride->handle($this->fieldKey, $this->prompt, auth()->user(), request()->ip()), '');

        $this->prompt = $override?->prompt ?? PromptTemplate::normalize($this->prompt);
        $this->resetErrorBag();

        $this->dispatch('toast', title: __('admin.toast.saved'), body: __($override !== null ? 'admin.ai_prompts.saved' : 'admin.ai_prompts.saved_default', ['field' => $this->field()->label()]), type: 'success');
    }

    public function resetToDefault(ResetAiPromptOverride $resetOverride): void
    {
        $this->authorize('update', AiPromptOverride::class);

        $resetOverride->handle($this->fieldKey, auth()->user(), request()->ip());

        $this->prompt = $this->field()->defaultTemplate();
        $this->resetErrorBag();

        $this->dispatch('toast', title: __('admin.toast.done'), body: __('admin.ai_prompts.reset_done', ['field' => $this->field()->label()]), type: 'success');
    }

    public function render(): View
    {
        $field = $this->field();
        $text = PromptTemplate::normalize($this->prompt);
        $override = AiPromptOverride::query()->where('field_key', $field->key)->with('updatedBy:id,name')->first();

        return view('livewire.admin.settings.ai-prompts.edit', [
            'field' => $field,
            'defaults' => collect(config('app.supported_locales'))->mapWithKeys(fn (string $locale): array => [$locale => $field->defaultTemplate($locale)])->all(),
            'override' => $override !== null && trim($override->prompt) !== '' ? $override : null,
            'length' => mb_strlen($text),
            'unknown' => PromptTemplate::unknownPlaceholders($field, $text),
            'missing' => PromptTemplate::missingPlaceholders($field, $text),
            'brandWarnings' => PromptTemplate::brandWarnings($text),
            'preview' => $text !== '' ? PromptTemplate::preview($field, $text) : '',
        ])->title($field->label().' · '.__('admin.ai_prompts.title'));
    }

    private function field(): FieldSpec
    {
        return FieldRegistry::get($this->fieldKey);
    }
}
