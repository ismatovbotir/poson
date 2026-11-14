<?php

namespace App\Livewire\Admin\Careers\Applications;

use App\Enums\JobApplicationCvStatus;
use App\Enums\JobApplicationStatus;
use App\Enums\VacancyDepartment;
use App\Livewire\Admin\Concerns\WithAdminPagination;
use App\Models\JobApplication;
use App\Models\Vacancy;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Job applications (plan 31): candidate, vacancy or open-application
 * department, status, CV state, date; filters (vacancy, department, status,
 * CV state) and a name / phone search; 25 per page, vacancy titles eager
 * loaded. Opens the detail screen. careers.applications (Admin). The CV
 * file_id / pending path are never selected into the view.
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    use WithAdminPagination;

    public const SEARCH_MAX = 100;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $vacancy = '';

    #[Url(except: '')]
    public string $department = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $cvStatus = '';

    public function mount(): void
    {
        $this->authorize('viewAny', JobApplication::class);
        $this->search = mb_substr($this->search, 0, self::SEARCH_MAX);
    }

    public function hydrate(): void
    {
        $this->authorize('viewAny', JobApplication::class);
    }

    public function updated(string $property): void
    {
        if ($property === 'search') {
            $this->search = mb_substr($this->search, 0, self::SEARCH_MAX);
        }

        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'vacancy', 'department', 'status', 'cvStatus');
        $this->resetPage();
    }

    /**
     * Vacancies for the filter select (computed once per request).
     *
     * @return Collection<int, Vacancy>
     */
    #[Computed]
    public function vacancyOptions(): Collection
    {
        return Vacancy::query()->ordered()->get(['id', 'title']);
    }

    public function render(): View
    {
        $search = trim($this->search);
        $digits = preg_replace('/\D+/', '', $search);

        $applications = JobApplication::query()
            ->select(['id', 'vacancy_id', 'department', 'status', 'name', 'phone', 'cv_status', 'created_at'])
            ->with('vacancy:id,title')
            ->when(ctype_digit($this->vacancy), fn ($query) => $query->where('vacancy_id', (int) $this->vacancy))
            ->when($this->vacancy === 'open', fn ($query) => $query->whereNull('vacancy_id'))
            ->when(VacancyDepartment::tryFrom($this->department) !== null, fn ($query) => $query->where('department', $this->department))
            ->when(JobApplicationStatus::tryFrom($this->status) !== null, fn ($query) => $query->where('status', $this->status))
            ->when(JobApplicationCvStatus::tryFrom($this->cvStatus) !== null, fn ($query) => $query->where('cv_status', $this->cvStatus))
            ->when($search !== '', function ($query) use ($search, $digits): void {
                $query->where(function ($query) use ($search, $digits): void {
                    $query->where('name', 'like', '%'.addcslashes($search, '%_\\').'%');

                    if (is_string($digits) && strlen($digits) >= 3) {
                        $query->orWhere('phone', 'like', '%'.$digits.'%');
                    }
                });
            })
            ->latest('created_at')
            ->latest('id')
            ->paginate(25);

        return view('livewire.admin.careers.applications.index', [
            'items' => $applications,
            'vacancies' => $this->vacancyOptions(),
            'filtering' => $search !== '' || $this->vacancy !== '' || $this->department !== '' || $this->status !== '' || $this->cvStatus !== '',
        ])->title(__('careers.admin.applications.title'));
    }
}
