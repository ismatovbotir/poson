<?php

namespace App\Livewire\Admin\Attributes;

use App\Actions\Catalog\DeleteAttribute;
use App\Actions\Catalog\SaveAttribute;
use App\Enums\AttributeType;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Forms\Admin\Attributes\AttributeForm;
use App\Models\Attribute;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Create / edit a library attribute: code, name, unit, type (locked once
 * products have values), default filter flag and the options repeater.
 */
#[Layout('layouts::admin')]
class Form extends Component
{
    use HandlesActionErrors;

    public ?Attribute $attribute = null;

    public AttributeForm $form;

    public function mount(?Attribute $attribute = null): void
    {
        if ($attribute?->exists) {
            $this->authorize('view', $attribute);
            $this->attribute = $attribute;
            $this->form->setAttributeModel($attribute);

            return;
        }

        $this->authorize('create', Attribute::class);
        $this->form->addOption();
    }

    /**
     * Text can't be a filter; select types start with one empty option row.
     */
    public function updatedFormType(): void
    {
        $type = $this->form->typeEnum();

        if (! $type->canBeFilterable()) {
            $this->form->is_filterable = false;
        }

        if ($type->hasOptions() && $this->form->options === []) {
            $this->form->addOption();
        }
    }

    public function addOption(): void
    {
        $this->form->addOption();
    }

    public function removeOption(int $index): void
    {
        $this->form->removeOption($index);
    }

    public function moveOption(int $index, int $direction): void
    {
        $this->form->moveOption($index, $direction);
    }

    /**
     * Categories that assign the attribute (memoized for the request).
     */
    #[Computed]
    public function categoriesCount(): int
    {
        return $this->attribute?->categories()->count() ?? 0;
    }

    public function typeLocked(): bool
    {
        return $this->attribute?->hasValues() ?? false;
    }

    public function save(SaveAttribute $saveAttribute): void
    {
        if ($this->attribute) {
            $this->authorize('update', $this->attribute);
        } else {
            $this->authorize('create', Attribute::class);
        }

        if ($this->typeLocked()) {
            $this->form->type = $this->attribute->type->value;
        }

        $this->form->code = mb_strtolower(trim($this->form->code));
        $this->form->validate();

        $creating = $this->attribute === null;

        $attribute = $this->runFormAction(fn (): Attribute => $saveAttribute->handle($this->attribute, $this->form->attributeData(), $this->form->optionRows()));

        if ($creating) {
            session()->flash('toast', ['title' => __('admin.toast.saved'), 'body' => __('admin.attributes.created', ['name' => $attribute->name]), 'type' => 'success']);
            $this->redirectRoute('admin.attributes.edit', $attribute, navigate: true);

            return;
        }

        $this->attribute = $attribute->fresh();
        $this->form->setAttributeModel($this->attribute);

        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('admin.attributes.saved', ['name' => $attribute->name]));
    }

    public function delete(DeleteAttribute $deleteAttribute): void
    {
        if ($this->attribute === null) {
            return;
        }

        $this->authorize('delete', $this->attribute);

        if ($this->runToastAction(fn () => $deleteAttribute->handle($this->attribute))) {
            session()->flash('toast', ['title' => __('admin.toast.deleted'), 'body' => $this->attribute->name, 'type' => 'success']);
            $this->redirectRoute('admin.attributes.index', navigate: true);
        }
    }

    public function render(): View
    {
        $title = $this->attribute ? $this->attribute->name : __('admin.attributes.new');

        return view('livewire.admin.attributes.form', [
            'title' => $title,
            'types' => AttributeType::cases(),
            'type' => $this->form->typeEnum(),
            'typeLocked' => $this->typeLocked(),
            'categoriesCount' => $this->categoriesCount,
            'canSave' => $this->attribute ? auth()->user()->can('update', $this->attribute) : true,
            'canDelete' => $this->attribute && auth()->user()->can('delete', $this->attribute),
        ])->title($title);
    }
}
