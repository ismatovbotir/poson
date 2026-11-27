<?php

namespace App\Livewire\Admin\Office;

use App\Actions\Office\PublishAgentProfileVersion;
use App\Actions\Office\SaveAgentProfileVersion;
use App\Enums\AgentRole;
use App\Enums\AiKnowledgeSource;
use App\Enums\EscalationAction;
use App\Enums\EscalationTrigger;
use App\Enums\ToneOption;
use App\Models\AgentProfile;
use App\Models\AgentProfileVersion;
use App\Models\User;
use App\Support\Office\AgentGuidance;
use App\Support\Office\CoreRules;
use App\Support\Office\OfficeModels;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Virtual office → Education (/admin/office/education/{role}, plan 41
 * §8.4, office.education = Admin only, VO-10): the teaching of one agent
 * (instructions, rules, knowledge, examples, model, output limit, site
 * knowledge), the mandatory rules read-only, the version list with an
 * ordered line diff against the published version, save (new version), publish and
 * rollback (publish an older version). Validation and audit live in the
 * actions; the role is #[Locked] and allow-listed.
 */
#[Layout('layouts::admin')]
class Education extends Component
{
    #[Locked]
    public string $role = 'router';

    public string $instructions = '';

    public string $rules = '';

    public string $knowledge = '';

    /** @var list<array{kind: string, language: string, role: string, client: string, reply: string, note: string, source_feedback_id: int|null}> */
    public array $examples = [];

    /**
     * The structured guidance as form state (plan 46): lists are one item per line in a textarea.
     *
     * @var array<string, mixed>
     */
    public array $guidance = [];

    public string $tab = 'fields';

    public string $model = '';

    /** @var int|string|null Untyped: an empty input reaches SaveAgentProfileVersion's validation. */
    public $maxTokens = 0;

    public bool $useSiteKnowledge = true;

    public string $changeNote = '';

    #[Locked]
    public ?int $compareId = null;

    /**
     * The profile, read once per request (protected: not in the snapshot).
     */
    protected ?AgentProfile $loadedProfile = null;

    protected bool $profileLoaded = false;

    public function mount(string $role = 'router'): void
    {
        $this->authorize('office.education');

        $case = AgentRole::tryFrom($role);
        abort_unless($case !== null && in_array($case, AgentRole::profiles(), true), 404);

        $this->role = $case->value;
        $this->loadFrom($this->profile()?->currentVersion);
    }

    public function hydrate(): void
    {
        $this->authorize('office.education');
    }

    public function setTab(string $tab): void
    {
        $this->authorize('office.education');

        if (in_array($tab, ['fields', 'free', 'examples'], true)) {
            $this->tab = $tab;
        }
    }

    public function addExample(string $kind = 'good'): void
    {
        $this->authorize('office.education');

        $kind = $this->roleCase() === AgentRole::Router ? 'route' : (in_array($kind, ['good', 'bad'], true) ? $kind : 'good');

        if (count($this->examples) < (int) config('office.education.examples_max', 10)) {
            $this->examples[] = self::blankExample($kind);
        }
    }

    public function addCustomEscalation(): void
    {
        $this->authorize('office.education');

        if (count((array) ($this->guidance['escalations']['custom'] ?? [])) < (int) config('office.education.guidance.custom_escalations', 4)) {
            $this->guidance['escalations']['custom'][] = ['text' => '', 'action' => EscalationAction::Flag->value];
        }
    }

    public function removeCustomEscalation(int $index): void
    {
        $this->authorize('office.education');

        unset($this->guidance['escalations']['custom'][$index]);
        $this->guidance['escalations']['custom'] = array_values((array) ($this->guidance['escalations']['custom'] ?? []));
    }

    public function removeExample(int $index): void
    {
        $this->authorize('office.education');

        unset($this->examples[$index]);
        $this->examples = array_values($this->examples);
    }

    public function save(SaveAgentProfileVersion $save): void
    {
        $this->persist($save, publish: false);
    }

    public function saveAndPublish(SaveAgentProfileVersion $save): void
    {
        $this->persist($save, publish: true);
    }

    private function persist(SaveAgentProfileVersion $save, bool $publish): void
    {
        $this->authorize('office.education');

        try {
            $version = $save->handle($this->user(), $this->roleCase(), [
                'instructions' => $this->instructions,
                'rules' => $this->rules,
                'knowledge' => $this->knowledge,
                'examples' => $this->examples,
                'guidance' => $this->guidancePayload(),
                'model' => $this->model,
                'max_tokens' => $this->maxTokens,
                'change_note' => $this->changeNote,
                'use_site_knowledge' => $this->useSiteKnowledge,
            ], request()->ip(), $publish);
        } catch (ValidationException $error) {
            // Open the tab of the first error so the Admin sees it (the red dots mark the others).
            $this->tab = self::tabOf((string) array_key_first($error->errors()));

            throw $error;
        }

        $this->changeNote = '';
        $this->profileLoaded = false;

        $this->dispatch('toast', title: __('admin.toast.saved'), body: __($publish ? 'office.education.published' : 'office.education.saved', ['version' => $version->version]), type: 'success');

        if (SaveAgentProfileVersion::hasPlaceholders($version, $this->roleCase())) {
            $this->dispatch('toast', title: __('admin.toast.saved'), body: __('office.errors.placeholders_left'), type: 'error');
        }
    }

    public function publish(int $versionId, PublishAgentProfileVersion $publish): void
    {
        $this->authorize('office.education');

        $profile = $this->profile();
        abort_if($profile === null, 404);

        $version = $publish->handle($this->user(), $profile, $versionId, request()->ip());
        $this->profileLoaded = false;
        $this->loadFrom($version);

        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('office.education.published', ['version' => $version->version]), type: 'success');
    }

    /**
     * Load an older version into the form (to edit it into a new version).
     */
    public function edit(int $versionId): void
    {
        $this->authorize('office.education');

        $version = $this->profile()?->versions()->whereKey($versionId)->first();

        // A version of another agent (or a tampered id) is refused, never a blank form.
        if (! $version instanceof AgentProfileVersion) {
            $this->addError('version', __('office.errors.version_invalid'));

            return;
        }

        $this->loadFrom($version);
    }

    public function compare(?int $versionId): void
    {
        $this->authorize('office.education');

        if ($versionId !== null && ! $this->profile()?->versions()->whereKey($versionId)->exists()) {
            $this->addError('version', __('office.errors.version_invalid'));

            return;
        }

        $this->compareId = $versionId;
    }

    public function render(): View
    {
        $profile = $this->profile();
        $versions = $profile?->versions()->with('createdBy:id,name')->orderByDesc('version')->limit(30)->get() ?? collect();
        $current = $profile?->currentVersion;
        $compared = $this->compareId !== null ? $versions->firstWhere('id', $this->compareId) : null;

        return view('livewire.admin.office.education', [
            'roles' => AgentRole::profiles(),
            'current' => $current,
            'versions' => $versions,
            'compared' => $compared,
            'diff' => $compared !== null && $current !== null ? self::diff($this->diffText($current), $this->diffText($compared)) : [],
            'isRouter' => $this->roleCase() === AgentRole::Router,
            'pendingNewer' => $current === null ? [] : $versions->filter(fn (AgentProfileVersion $version): bool => $version->version > $current->version)->pluck('version')->sort()->values()->all(),
            'errorTabs' => $this->errorTabs(),
            'triggers' => EscalationTrigger::cases(),
            'actions' => EscalationAction::cases(),
            'tones' => ToneOption::cases(),
            'sources' => AiKnowledgeSource::cases(),
            'guidanceLimits' => (array) config('office.education.guidance'),
            'routable' => AgentRole::routable(),
            'coreRules' => CoreRules::text(),
            'models' => OfficeModels::allowed(),
            'limits' => (array) config('office.education'),
            'tokenBounds' => (array) config('office.agents.'.$this->role),
        ])->title(__('office.education.title'));
    }

    /**
     * An ordered line diff (longest common subsequence) of the published and another version:
     * rows "same", "published" (only in the published one) and "other" (only in the other one).
     *
     * @return list<array{type: string, line: string}>
     */
    public static function diff(string $published, string $other): array
    {
        $old = array_slice(preg_split('/\R/', $published) ?: [], 0, 300);
        $new = array_slice(preg_split('/\R/', $other) ?: [], 0, 300);
        $n = count($old);
        $m = count($new);
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $old[$i] === $new[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $rows = [];
        $i = 0;
        $j = 0;

        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $old[$i] === $new[$j]) {
                $rows[] = ['type' => 'same', 'line' => $old[$i]];
                $i++;
                $j++;
            } elseif ($j < $m && ($i >= $n || $lcs[$i][$j + 1] >= $lcs[$i + 1][$j])) {
                $rows[] = ['type' => 'other', 'line' => $new[$j]];
                $j++;
            } else {
                $rows[] = ['type' => 'published', 'line' => $old[$i]];
                $i++;
            }
        }

        return $rows;
    }

    private function loadFrom(?AgentProfileVersion $version): void
    {
        $agent = (array) config('office.agents.'.$this->role);

        $this->instructions = (string) $version?->instructions;
        $this->rules = (string) $version?->rules;
        $this->knowledge = (string) $version?->knowledge;
        $this->examples = array_values(array_map(fn (array $example): array => [
            'kind' => in_array($example['kind'] ?? null, ['good', 'bad', 'route'], true) ? (string) $example['kind'] : 'good',
            'language' => in_array($example['language'] ?? null, ['uz', 'ru', 'en'], true) ? (string) $example['language'] : 'uz',
            'role' => (string) ($example['role'] ?? AgentRole::Other->value),
            'client' => (string) ($example['client'] ?? ''),
            'reply' => (string) ($example['reply'] ?? ''),
            'note' => (string) ($example['note'] ?? ''),
            'source_feedback_id' => isset($example['source_feedback_id']) ? (int) $example['source_feedback_id'] : null,
        ], (array) $version?->examples));
        $this->guidance = self::guidanceForm(($version?->guidanceFor($this->roleCase()) ?? AgentGuidance::defaults($this->roleCase()))->toArray());
        $this->model = $version?->model ?? (string) ($agent['model'] ?? OfficeModels::allowed()[0]);
        $this->maxTokens = $version?->max_tokens ?? (int) ($agent['max_tokens'] ?? 300);
        $this->useSiteKnowledge = $this->profile()?->use_site_knowledge ?? true;
    }

    /**
     * The tab that holds a field.
     */
    public static function tabOf(string $field): string
    {
        return match (true) {
            str_starts_with($field, 'guidance') => 'fields',
            str_starts_with($field, 'examples') => 'examples',
            default => 'free',
        };
    }

    /**
     * Tabs that hold an error now (the red dot).
     *
     * @return list<string>
     */
    private function errorTabs(): array
    {
        return array_values(array_unique(array_map(self::tabOf(...), $this->getErrorBag()->keys())));
    }

    /**
     * @return array{kind: string, language: string, role: string, client: string, reply: string, note: string, source_feedback_id: int|null}
     */
    private static function blankExample(string $kind): array
    {
        return ['kind' => $kind, 'language' => 'uz', 'role' => AgentRole::Sales->value, 'client' => '', 'reply' => '', 'note' => '', 'source_feedback_id' => null];
    }

    /**
     * The text compared in the version diff: the instructions and the canonical guidance (plan 46).
     */
    private function diffText(AgentProfileVersion $version): string
    {
        $guidance = $version->guidanceFor($this->roleCase())?->canonicalText();

        return $version->instructions.($guidance ? "\n".$guidance : '');
    }

    /**
     * Stored guidance to form state: the three lists become one-per-line text.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function guidanceForm(array $data): array
    {
        foreach (['must_ask', 'service_facts', 'forbidden_topics'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = implode("\n", (array) $data[$key]);
            }
        }

        return $data;
    }

    /**
     * Form state to the guidance payload of SaveAgentProfileVersion (lines to lists; the action validates).
     *
     * @return array<string, mixed>
     */
    private function guidancePayload(): array
    {
        $payload = $this->guidance;

        foreach (['must_ask', 'service_facts', 'forbidden_topics'] as $key) {
            if (array_key_exists($key, $payload)) {
                // A tampered non-string value is an empty list, never a cast of an array.
                $lines = is_string($payload[$key]) ? (preg_split('/\R/u', $payload[$key]) ?: []) : [];
                $payload[$key] = array_values(array_filter(array_map('trim', $lines), fn (string $line): bool => $line !== ''));
            }
        }

        return $payload;
    }

    private function profile(): ?AgentProfile
    {
        if (! $this->profileLoaded) {
            $this->loadedProfile = AgentProfile::forRole($this->roleCase())?->load('currentVersion');
            $this->profileLoaded = true;
        }

        return $this->loadedProfile;
    }

    private function roleCase(): AgentRole
    {
        return AgentRole::from($this->role);
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
