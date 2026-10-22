<?php

namespace App\Livewire\Admin\Customers;

use App\Actions\Customers\SaveCustomer;
use App\Enums\PriceLevel;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Forms\Admin\Customers\CustomerForm;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Create / edit a customer (plan 05b T5): type, name, INN, phone, email,
 * address, notes, manager (per permission: anyone with
 * customers.update-any, else me / unassigned), price level (Admin only,
 * customers.price-level), contacts repeater with one primary, telegram_id
 * shown read-only.
 */
#[Layout('layouts::admin')]
class Form extends Component
{
    use HandlesActionErrors;

    #[Locked]
    public ?Customer $customer = null;

    public CustomerForm $form;

    public function mount(?Customer $customer = null): void
    {
        if ($customer?->exists) {
            $this->authorize('update', $customer);
            $this->customer = $customer;
            $this->form->setCustomer($customer);

            return;
        }

        $this->authorize('create', Customer::class);
        $this->form->manager_id = (string) $this->user()->id;
        $this->form->price_level = $this->canChangePriceLevel() ? PriceLevel::Retail->value : '';
        $this->form->addContact();
    }

    /**
     * Manager choices: id => name (the current manager stays listed).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function managerOptions(): array
    {
        $options = app(SaveCustomer::class)->managerCandidates($this->user())->pluck('name', 'id')->all();

        if ($this->customer?->manager_id !== null && ! isset($options[$this->customer->manager_id])) {
            $options[$this->customer->manager_id] = (string) $this->customer->manager?->name;
        }

        return $options;
    }

    public function canChangePriceLevel(): bool
    {
        return $this->user()->can('changePriceLevel', Customer::class);
    }

    public function addContact(): void
    {
        $this->form->addContact();
    }

    public function removeContact(int $index): void
    {
        $this->form->removeContact($index);
    }

    public function save(SaveCustomer $saveCustomer): void
    {
        if ($this->customer !== null) {
            $this->authorize('update', $this->customer);
        } else {
            $this->authorize('create', Customer::class);
        }

        $this->form->validate();

        $creating = $this->customer === null;
        $customer = $this->runFormAction(fn (): Customer => $saveCustomer->handle(
            $this->customer,
            $this->form->customerData($this->canChangePriceLevel()),
            $this->user(),
        ));

        session()->flash('toast', [
            'title' => __('admin.toast.saved'),
            'body' => __($creating ? 'crm.customers.toast.created' : 'crm.customers.toast.saved', ['name' => $customer->name]),
            'type' => 'success',
        ]);

        $this->redirectRoute('admin.customers.show', $customer, navigate: true);
    }

    public function render(): View
    {
        $title = $this->customer ? __('crm.customers.edit_title', ['name' => $this->customer->name]) : __('crm.customers.new');

        return view('livewire.admin.customers.form', [
            'title' => $title,
            'canChangePriceLevel' => $this->canChangePriceLevel(),
            'storedPriceLevel' => $this->customer?->price_level ?? PriceLevel::Retail,
        ])->title($title);
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
