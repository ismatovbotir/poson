<?php

namespace App\Livewire\Admin\Careers\Vacancies;

use App\Actions\Careers\DeleteVacancy;
use App\Actions\Careers\SaveVacancy;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Forms\Admin\Careers\VacancyForm;
use App\Models\Vacancy;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Create / edit a vacancy (plan 31): locale tabs for the texts, requirements
 * and offer (one item per line), main fields and the publish switch in the
 * side card. Publishing needs all three locales complete (SaveVacancy).
 */
#[Layout('layouts::admin')]
class Form extends Component
{
    use HandlesActionErrors;

    #[Locked]
    public ?Vacancy $vacancy = null;

    public VacancyForm $form;

    public function mount(?Vacancy $vacancy = null): void
    {
        if ($vacancy?->exists) {
            $this->authorize('view', $vacancy);
            $this->vacancy = $vacancy;
            $this->form->setVacancy($vacancy);

            return;
        }

        $this->authorize('create', Vacancy::class);
    }

    public function hydrate(): void
    {
        if ($this->vacancy) {
            $this->authorize('view', $this->vacancy);
        } else {
            $this->authorize('create', Vacancy::class);
        }
    }

    public function save(SaveVacancy $saveVacancy): void
    {
        if ($this->vacancy) {
            $this->authorize('update', $this->vacancy);
        } else {
            $this->authorize('create', Vacancy::class);
        }

        $this->form->validate();

        $creating = $this->vacancy === null;
        $vacancy = $this->runFormAction(fn (): Vacancy => $saveVacancy->handle($this->vacancy, $this->form->vacancyData()));
        $name = (string) $vacancy->getTranslation('title', 'uz', false);

        if ($creating) {
            session()->flash('toast', ['title' => __('admin.toast.saved'), 'body' => $name, 'type' => 'success']);
            $this->redirectRoute('admin.careers.vacancies.edit', $vacancy, navigate: true);

            return;
        }

        $this->vacancy = $vacancy->fresh();
        $this->form->setVacancy($this->vacancy);

        $this->dispatch('toast', title: __('admin.toast.saved'), body: $name);
    }

    public function delete(DeleteVacancy $deleteVacancy): void
    {
        if ($this->vacancy === null) {
            return;
        }

        $this->authorize('delete', $this->vacancy);

        if ($this->runToastAction(fn () => $deleteVacancy->handle($this->vacancy))) {
            session()->flash('toast', ['title' => __('admin.toast.deleted'), 'body' => (string) $this->vacancy->getTranslation('title', 'uz', false), 'type' => 'success']);
            $this->redirectRoute('admin.careers.vacancies.index', navigate: true);
        }
    }

    public function render(): View
    {
        $title = $this->vacancy
            ? (string) $this->vacancy->getTranslation('title', 'uz', false)
            : __('careers.admin.vacancies.new');
        $user = auth()->user();

        return view('livewire.admin.careers.vacancies.form', [
            'title' => $title,
            'applicationsCount' => $this->vacancy ? $this->vacancy->applications()->count() : 0,
            'canSave' => $this->vacancy ? $user->can('update', $this->vacancy) : true,
            'canDelete' => $this->vacancy && $user->can('delete', $this->vacancy),
        ])->title($title);
    }
}
