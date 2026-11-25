<?php

namespace App\Livewire\Admin\Office;

use App\Actions\Office\PublishAgentProfileVersion;
use App\Actions\Office\SaveAgentProfileVersion;
use App\Enums\AgentRole;
use App\Models\AgentProfile;
use App\Models\AgentProfileVersion;
use App\Models\User;
use App\Support\Office\CoreRules;
use App\Support\Office\OfficeModels;
use Illuminate\Contracts\View\View;
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

    /** @var list<array{client: string, reply: string, note: string}> */
    public array $examples = [];

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

    public function addExample(): void
    {
        $this->authorize('office.education');

        if (count($this->examples) < (int) config('office.education.examples_max', 10)) {
            $this->examples[] = ['client' => '', 'reply' => '', 'note' => ''];
        }
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

        $version = $save->handle($this->user(), $this->roleCase(), [
            'instructions' => $this->instructions,
            'rules' => $this->rules,
            'knowledge' => $this->knowledge,
            'examples' => $this->examples,
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'change_note' => $this->changeNote,
            'use_site_knowledge' => $this->useSiteKnowledge,
        ], request()->ip(), $publish);

        $this->changeNote = '';
        $this->profileLoaded = false;

        $this->dispatch('toast', title: __('admin.toast.saved'), body: __($publish ? 'office.education.published' : 'office.education.saved', ['version' => $version->version]), type: 'success');
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
            'diff' => $compared !== null && $current !== null ? self::diff($current->instructions, $compared->instructions) : [],
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
            'client' => (string) ($example['client'] ?? ''),
            'reply' => (string) ($example['reply'] ?? ''),
            'note' => (string) ($example['note'] ?? ''),
        ], (array) $version?->examples));
        $this->model = $version?->model ?? (string) ($agent['model'] ?? OfficeModels::allowed()[0]);
        $this->maxTokens = $version?->max_tokens ?? (int) ($agent['max_tokens'] ?? 300);
        $this->useSiteKnowledge = $this->profile()?->use_site_knowledge ?? true;
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
