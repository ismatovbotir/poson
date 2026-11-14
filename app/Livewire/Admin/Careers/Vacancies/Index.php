<?php

namespace App\Livewire\Admin\Careers\Vacancies;

use App\Actions\Careers\DeleteVacancy;
use App\Actions\Careers\PublishVacancy;
use App\Enums\VacancyDepartment;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Admin\Concerns\WithAdminPagination;
use App\Models\Vacancy;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Vacancies list (plan 31): title (uz), department, location, type, number of
 * applications, state; filters (state, department), publish toggle and delete
 * (blocked while applications exist). careers.vacancies (Admin).
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    use HandlesActionErrors, WithAdminPagination;

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $department = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Vacancy::class);
    }

    public function hydrate(): void
    {
        $this->authorize('viewAny', Vacancy::class);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedDepartment(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('status', 'department');
        $this->resetPage();
    }

    public function togglePublished(int $id, PublishVacancy $publishVacancy): void
    {
        $vacancy = Vacancy::query()->findOrFail($id);
        $this->authorize('update', $vacancy);

        if ($this->runToastAction(fn () => $publishVacancy->handle($vacancy, ! $vacancy->is_published))) {
            $this->dispatch('toast', title: __('admin.toast.saved'), body: $vacancy->getTranslation('title', 'uz', false));
        }
    }

    public function delete(int $id, DeleteVacancy $deleteVacancy): void
    {
        $vacancy = Vacancy::query()->findOrFail($id);
        $this->authorize('delete', $vacancy);

        if ($this->runToastAction(fn () => $deleteVacancy->handle($vacancy))) {
            $this->dispatch('toast', title: __('admin.toast.deleted'), body: $vacancy->getTranslation('title', 'uz', false));
        }
    }

    public function render(): View
    {
        $vacancies = Vacancy::query()
            ->withCount('applications')
            ->when($this->status === 'published', fn ($query) => $query->where('is_published', true))
            ->when($this->status === 'draft', fn ($query) => $query->where('is_published', false))
            ->when(VacancyDepartment::tryFrom($this->department) !== null, fn ($query) => $query->where('department', $this->department))
            ->ordered()
            ->paginate(25);

        return view('livewire.admin.careers.vacancies.index', [
            'items' => $vacancies,
            'filtering' => $this->status !== '' || $this->department !== '',
            'canCreate' => auth()->user()->can('create', Vacancy::class),
            'canUpdate' => auth()->user()->can('update', Vacancy::class),
            'canDelete' => auth()->user()->can('delete', Vacancy::class),
        ])->title(__('careers.admin.vacancies.title'));
    }
}
