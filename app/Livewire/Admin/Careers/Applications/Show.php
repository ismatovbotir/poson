<?php

namespace App\Livewire\Admin\Careers\Applications;

use App\Actions\Careers\DeleteJobApplication;
use App\Actions\Careers\UpdateJobApplication;
use App\Enums\JobApplicationStatus;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Models\Activity;
use App\Models\JobApplication;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One job application (plan 31, CR-8): details, status select, internal
 * note, CV state with "Download CV" (downloadCv: careers.cv + Admin), delete
 * with confirmation (DeleteJobApplication) and the activity timeline. Only
 * the id is component state (#[Locked]); the row is read per request and
 * cv_file_id / cv_pending_path never reach the state or the HTML.
 */
#[Layout('layouts::admin')]
class Show extends Component
{
    use HandlesActionErrors;

    public const TIMELINE_LIMIT = 200;

    #[Locked]
    public int $applicationId = 0;

    public string $status = '';

    public string $internalNote = '';

    public function mount(int $jobApplication): void
    {
        $application = JobApplication::query()->findOrFail($jobApplication);
        $this->authorize('view', $application);

        $this->applicationId = $application->id;
        $this->status = $application->status->value;
        $this->internalNote = (string) $application->internal_note;
    }

    public function hydrate(): void
    {
        $this->authorize('view', $this->application());
    }

    public function save(UpdateJobApplication $update): void
    {
        $application = $this->application();
        $this->authorize('update', $application);

        $this->validate([
            'status' => ['required', Rule::enum(JobApplicationStatus::class)],
            'internalNote' => ['nullable', 'string', 'max:'.UpdateJobApplication::NOTE_MAX],
        ], attributes: ['status' => __('careers.admin.applications.status'), 'internalNote' => __('careers.admin.applications.note')]);

        $saved = $this->runFormAction(fn () => $update->handle($application, JobApplicationStatus::from($this->status), $this->internalNote, $this->user()), '');

        $this->internalNote = (string) $saved->internal_note;
        unset($this->activities);
        $this->dispatch('toast', title: __('admin.toast.saved'), body: '#J-'.$saved->id);
    }

    public function delete(DeleteJobApplication $delete): void
    {
        $application = $this->application();
        $this->authorize('delete', $application);

        $delete->handle($application, $this->user());

        session()->flash('toast', ['title' => __('admin.toast.deleted'), 'body' => '#J-'.$this->applicationId]);
        $this->redirectRoute('admin.careers.applications.index', navigate: true);
    }

    /**
     * @return Collection<int, Activity>
     */
    #[Computed]
    public function activities(): Collection
    {
        return Activity::query()
            ->where('subject_type', (new JobApplication)->getMorphClass())
            ->where('subject_id', $this->applicationId)
            ->with('user:id,name')
            ->latest('created_at')
            ->latest('id')
            ->limit(self::TIMELINE_LIMIT)
            ->get();
    }

    public function render(): View
    {
        $application = $this->application()->load('vacancy:id,title');

        return view('livewire.admin.careers.applications.show', [
            'application' => $application,
            'canUpdate' => $this->user()->can('update', $application),
            'canDelete' => $this->user()->can('delete', $application),
        ])->title('#J-'.$application->id.' · '.__('careers.admin.applications.title'));
    }

    private ?JobApplication $loaded = null;

    private function application(): JobApplication
    {
        return $this->loaded ??= JobApplication::query()->findOrFail($this->applicationId);
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
