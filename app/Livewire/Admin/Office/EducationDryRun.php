<?php

namespace App\Livewire\Admin\Office;

use App\Actions\Chat\RecordAiUsage;
use App\Actions\Office\RunEducationDryRun;
use App\Enums\AgentRole;
use App\Enums\AiUsageChannel;
use App\Enums\BusinessChatKind;
use App\Enums\GroupStatus;
use App\Enums\Role;
use App\Models\AgentProfile;
use App\Models\BusinessChat;
use App\Models\ChatAiUsageDaily;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Support\Office\OfficeSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Virtual office → Education → Test console (/admin/office/education/{role}/test, plan 46 §2.3, ED-4,
 * ED46-6): the published version (A) against a saved version (B) on one real, redacted conversation, nothing
 * saved. Admin only (office.education and the Admin role) in mount and every method; the office AI must be on.
 * Every step is a separate Livewire action. The state is only the picker choices and the #[Locked] run uuid:
 * the run record, with the filtered results, lives in the server cache (RunEducationDryRun); the transcript
 * is never in the state or the page. Results are rendered escaped.
 */
#[Layout('layouts::admin')]
class EducationDryRun extends Component
{
    #[Locked]
    public string $role = 'sales';

    public string $sourceType = 'chat';

    public ?int $sourceId = null;

    public ?int $versionB = null;

    #[Locked]
    public ?string $runId = null;

    public function mount(string $role = 'sales'): void
    {
        $this->guard();

        $case = AgentRole::tryFrom($role);
        abort_unless($case !== null && in_array($case, AgentRole::profiles(), true), 404);

        $this->role = $case->value;
    }

    public function hydrate(): void
    {
        $this->guard();
    }

    /**
     * Step 1: starts a run (counted against the limits) and calls the router.
     */
    public function start(RunEducationDryRun $dry): void
    {
        $this->guard();

        $started = $dry->start($this->user(), AgentRole::from($this->role), $this->sourceType, (int) $this->sourceId, (int) $this->versionB);
        $this->runId = $started['run'];
    }

    /**
     * Steps 2 and 3 (agent A, agent B; for the router: router B).
     */
    public function runStep(int $step, RunEducationDryRun $dry): void
    {
        $this->guard();

        $dry->step($this->user(), (string) $this->runId, $step);
    }

    public function render(RunEducationDryRun $dry): View
    {
        $this->guard();

        $role = AgentRole::from($this->role);
        $profile = AgentProfile::forRole($role);
        $run = $dry->find($this->user(), $this->runId);
        $caps = OfficeSettings::caps(AiUsageChannel::OfficeDry);
        $usage = ChatAiUsageDaily::query()->where('day', RecordAiUsage::day())->where('channel', AiUsageChannel::OfficeDry->value)->first();

        return view('livewire.admin.office.education-dry-run', [
            'roles' => AgentRole::profiles(),
            'isRouter' => $role === AgentRole::Router,
            'lastStep' => $role === AgentRole::Router ? 2 : 3,
            'aiOn' => OfficeSettings::aiEnabled(),
            'versions' => $profile?->versions()->orderByDesc('version')->limit(30)->get(['id', 'version', 'change_note']) ?? collect(),
            'currentId' => $profile?->current_version_id,
            'chats' => BusinessChat::query()->where('kind', BusinessChatKind::Client->value)->orderByDesc('id')->limit(20)->get(['id', 'first_name']),
            'groups' => TelegramGroup::query()->where('status', GroupStatus::Tracked->value)->orderByDesc('id')->limit(20)->get(['id', 'title']),
            'run' => $run,
            'caps' => $caps,
            'usedRequests' => (int) ($usage?->requests ?? 0),
        ])->title(__('office.dry.title'));
    }

    private function guard(): void
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->can('office.education') || ! $user->hasRole(Role::Admin->value)) {
            throw new AuthorizationException;
        }
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
