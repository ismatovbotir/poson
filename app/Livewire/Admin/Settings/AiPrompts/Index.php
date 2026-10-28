<?php

namespace App\Livewire\Admin\Settings\AiPrompts;

use App\Models\AiPromptOverride;
use App\Support\Ai\FieldRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Settings → AI prompts (/admin/settings/ai-prompts, plan 12): the AI
 * fields grouped by screen (FieldRegistry::groups()), each with a Default
 * or Customized badge (who and when) and an Edit link. ai-prompts.manage
 * (Admin only). The overrides are read once (one query with the editor's
 * name), never per field through the cache.
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', AiPromptOverride::class);
    }

    public function render(): View
    {
        return view('livewire.admin.settings.ai-prompts.index', [
            'groups' => FieldRegistry::groups(),
            'overrides' => $this->overrides(),
        ])->title(__('admin.ai_prompts.title'));
    }

    /**
     * Customized fields: key => editor name (null when the user was deleted) and date.
     *
     * @return array<string, array{by: string|null, at: Carbon|null}>
     */
    private function overrides(): array
    {
        return AiPromptOverride::query()
            ->leftJoin('users', 'users.id', '=', 'ai_prompt_overrides.updated_by')
            ->get(['ai_prompt_overrides.field_key', 'ai_prompt_overrides.prompt', 'ai_prompt_overrides.updated_at', 'users.name as editor_name'])
            ->filter(fn (AiPromptOverride $override): bool => trim($override->prompt) !== '' && FieldRegistry::has($override->field_key))
            ->mapWithKeys(fn (AiPromptOverride $override): array => [$override->field_key => [
                'by' => $override->getAttribute('editor_name'),
                'at' => $override->updated_at,
            ]])
            ->all();
    }
}
