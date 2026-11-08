<?php

namespace App\Livewire\Admin\Repairs;

use App\Actions\Repairs\AddRepairActivity;
use App\Actions\Repairs\ApproveRepairEstimate;
use App\Actions\Repairs\AssignRepair;
use App\Actions\Repairs\ChangeRepairStatus;
use App\Enums\RepairStatus;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Models\Activity;
use App\Models\Repair;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Repair page (plan 24 B2): status control (cancel asks for a note in a
 * modal), take / release (engineers) and assign (Admin), contact and device,
 * diagnosis / work / parts, coverage, costs (only with repairs.costs: the
 * amounts are read in the view behind that check and never held in a public
 * property), the activity timeline and "add note". Read-only for users who
 * may only view the repair (a sales manager).
 *
 * Every write authorizes against RepairPolicy and goes through an action.
 */
#[Layout('layouts::admin')]
class Show extends Component
{
    use HandlesActionErrors;

    /**
     * Timeline entries shown.
     */
    public const TIMELINE_LIMIT = 200;

    #[Locked]
    public Repair $repair;

    /**
     * Opaque marker of the estimate this page showed (ApproveRepairEstimate::version()); empty without cost rights.
     */
    #[Locked]
    public string $approvalVersion = '';

    public string $approveNote = '';

    public string $cancelNote = '';

    public string $activityBody = '';

    public string $assignTo = '';

    public function mount(Repair $repair): void
    {
        $this->authorize('view', $repair);
        $this->repair = $repair;
        $this->assignTo = (string) $repair->assigned_to_id;
        $this->syncApprovalVersion();
    }

    /**
     * Every later request: the repair may have been reassigned meanwhile.
     */
    public function hydrate(): void
    {
        $this->authorize('view', $this->repair);
    }

    public function changeStatus(string $status, ChangeRepairStatus $changeStatus): void
    {
        $this->authorize('update', $this->repair);

        $to = RepairStatus::tryFrom($status);

        // Cancelling goes through cancelRepair (note).
        if ($to === null || $to === RepairStatus::Cancelled) {
            return;
        }

        if ($this->runToastAction(fn () => $changeStatus->handle($this->repair, $to, $this->user()))) {
            $this->refreshRepair();
            $this->dispatch('toast', title: __('admin.toast.saved'), body: __('repairs.toast.status', ['status' => $to->label()]));
        }
    }

    public function cancelRepair(ChangeRepairStatus $changeStatus): void
    {
        $this->authorize('update', $this->repair);

        $this->validate([
            'cancelNote' => ['required', 'string', 'max:'.ChangeRepairStatus::NOTE_MAX],
        ], attributes: ['cancelNote' => __('repairs.fields.note')]);

        try {
            $changeStatus->handle($this->repair, RepairStatus::Cancelled, $this->user(), $this->cancelNote);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['cancelNote' => collect($exception->errors())->flatten()->all()]);
        }

        $this->reset('cancelNote');
        $this->refreshRepair();
        $this->dispatch('repair-cancelled');
        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('repairs.toast.status', ['status' => RepairStatus::Cancelled->label()]));
    }

    public function approveEstimate(ApproveRepairEstimate $approve): void
    {
        $this->authorize('approveEstimate', $this->repair);

        $this->validate([
            'approveNote' => ['required', 'string', 'max:'.ApproveRepairEstimate::NOTE_MAX],
        ], attributes: ['approveNote' => __('repairs.fields.approval_note')]);

        try {
            $approve->handle($this->repair, $this->user(), $this->approveNote, $this->approvalVersion);
        } catch (ValidationException $exception) {
            $this->dispatch('toast', title: __('admin.toast.error'), body: collect($exception->errors())->flatten()->first(), type: 'error');
            $this->refreshRepair();

            return;
        }

        $this->reset('approveNote');
        $this->refreshRepair();
        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('repairs.toast.approved'));
    }

    public function take(AssignRepair $assignRepair): void
    {
        $this->authorize('take', $this->repair);

        if ($this->runToastAction(fn () => $assignRepair->take($this->repair, $this->user()))) {
            $this->refreshRepair();
            $this->dispatch('toast', title: __('admin.toast.done'), body: __('repairs.toast.taken', ['number' => $this->repair->number]));
        }
    }

    public function release(AssignRepair $assignRepair): void
    {
        $this->authorize('release', $this->repair);

        if ($this->runToastAction(fn () => $assignRepair->release($this->repair, $this->user()))) {
            $this->refreshRepair();
            $this->dispatch('toast', title: __('admin.toast.done'), body: __('repairs.toast.released', ['number' => $this->repair->number]));
        }
    }

    public function assign(AssignRepair $assignRepair): void
    {
        $this->authorize('assign', $this->repair);

        $userId = ctype_digit($this->assignTo) ? (int) $this->assignTo : null;

        if ($this->runToastAction(fn () => $assignRepair->assign($this->repair, $userId, $this->user()))) {
            $this->refreshRepair();
            $this->dispatch('toast', title: __('admin.toast.saved'), body: __('repairs.toast.assigned', ['name' => $this->repair->assignedTo?->name ?? __('repairs.unassigned')]));
        }
    }

    public function addActivity(AddRepairActivity $addActivity): void
    {
        $this->authorize('note', $this->repair);

        $this->validate([
            'activityBody' => ['required', 'string', 'max:'.AddRepairActivity::BODY_MAX],
        ], attributes: ['activityBody' => __('repairs.activity.body')]);

        $addActivity->handle($this->repair, $this->activityBody, $this->user());

        $this->reset('activityBody');
        unset($this->activities);
        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('repairs.toast.note_added'));
    }

    /**
     * @return Collection<int, Activity>
     */
    #[Computed]
    public function activities(): Collection
    {
        return $this->repair->activities()
            ->with('user:id,name')
            ->latest('created_at')
            ->latest('id')
            ->limit(self::TIMELINE_LIMIT)
            ->get();
    }

    /**
     * Engineer choices for "assign" (empty without repairs.assign).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function candidates(): array
    {
        if (! $this->user()->can('assign', $this->repair)) {
            return [];
        }

        return app(AssignRepair::class)->candidates()->pluck('name', 'id')->all();
    }

    public function render(): View
    {
        $user = $this->user();
        $this->repair->loadMissing(['assignedTo:id,name', 'createdBy:id,name', 'customer:id,name', 'contact:id,name,position', 'lead']);

        $repair = $this->repair;
        $canEdit = $user->can('update', $repair);

        return view('livewire.admin.repairs.show', [
            'canEdit' => $canEdit,
            'canNote' => $user->can('note', $repair),
            'canApprove' => $user->can('approveEstimate', $repair),
            'canTake' => $user->can('take', $repair),
            'canRelease' => $user->can('release', $repair),
            'canAssign' => $user->can('assign', $repair),
            'canCosts' => $user->can('viewCosts', $repair),
            'canEditCosts' => $user->can('editCosts', $repair),
            'canViewCustomer' => $repair->customer !== null && $user->can('view', $repair->customer),
            'canViewLead' => $repair->lead !== null && $user->can('view', $repair->lead),
            'statuses' => array_values(array_filter($repair->status->next(), fn (RepairStatus $status): bool => $status !== RepairStatus::Cancelled && $repair->status->canTransitionTo($status, $repair->coverage))),
            'canCancel' => $canEdit && $repair->status->canTransitionTo(RepairStatus::Cancelled, $repair->coverage),
        ])->title($repair->number.' · '.($repair->company ?: $repair->contact_name));
    }

    private function refreshRepair(): void
    {
        $this->repair->refresh();
        $this->assignTo = (string) $this->repair->assigned_to_id;
        $this->syncApprovalVersion();
        unset($this->activities, $this->candidates);
    }

    private function syncApprovalVersion(): void
    {
        $this->approvalVersion = $this->user()->can('editCosts', $this->repair) ? ApproveRepairEstimate::version($this->repair) : '';
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
