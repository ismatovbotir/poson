<?php

namespace App\Livewire\Admin\RollTypes;

use App\Actions\Catalog\DeleteRollType;
use App\Actions\Catalog\SaveRollType;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Forms\Admin\RollTypes\RollTypeForm;
use App\Models\RollType;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Create / edit a label roll type (plan 08): labels per roll, core
 * diameter, active, sort. Products pick their roll types on the product
 * form (Price & sale).
 */
#[Layout('layouts::admin')]
class Form extends Component
{
    use HandlesActionErrors;

    #[Locked]
    public ?RollType $rollType = null;

    public RollTypeForm $form;

    public function mount(?RollType $rollType = null): void
    {
        if ($rollType?->exists) {
            $this->authorize('view', $rollType);
            $this->rollType = $rollType;
            $this->form->setRollType($rollType);

            return;
        }

        $this->authorize('create', RollType::class);
    }

    public function save(SaveRollType $saveRollType): void
    {
        if ($this->rollType) {
            $this->authorize('update', $this->rollType);
        } else {
            $this->authorize('create', RollType::class);
        }

        $this->form->validate();

        $creating = $this->rollType === null;
        $rollType = $this->runFormAction(fn (): RollType => $saveRollType->handle($this->rollType, $this->form->rollTypeData()));

        if ($creating) {
            session()->flash('toast', ['title' => __('admin.toast.saved'), 'body' => __('admin.roll_types.created', ['name' => $rollType->label()]), 'type' => 'success']);
            $this->redirectRoute('admin.roll-types.edit', $rollType, navigate: true);

            return;
        }

        $this->rollType = $rollType->fresh();
        $this->form->setRollType($this->rollType);

        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('admin.roll_types.saved', ['name' => $this->rollType->label()]));

        // Hiding it may leave active label products without an offered roll type: warn, don't block.
        if (! $this->rollType->is_active && ($stranded = SaveRollType::productsLeftWithoutRoll($this->rollType)) > 0) {
            $this->dispatch('toast', title: __('admin.roll_types.warning_title'), body: trans_choice('admin.roll_types.left_without_roll', $stranded, ['count' => $stranded]), type: 'error');
        }
    }

    public function delete(DeleteRollType $deleteRollType): void
    {
        if ($this->rollType === null) {
            return;
        }

        $this->authorize('delete', $this->rollType);

        if ($this->runToastAction(fn () => $deleteRollType->handle($this->rollType))) {
            session()->flash('toast', ['title' => __('admin.toast.deleted'), 'body' => $this->rollType->label(), 'type' => 'success']);
            $this->redirectRoute('admin.roll-types.index', navigate: true);
        }
    }

    public function render(): View
    {
        $title = $this->rollType ? $this->rollType->label() : __('admin.roll_types.new');
        $user = auth()->user();

        return view('livewire.admin.roll-types.form', [
            'title' => $title,
            'productsCount' => $this->rollType ? $this->rollType->products()->count() : 0,
            'canSave' => $this->rollType ? $user->can('update', $this->rollType) : true,
            'canDelete' => $this->rollType && $user->can('delete', $this->rollType),
        ])->title($title);
    }
}
