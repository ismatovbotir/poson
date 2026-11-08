<?php

namespace App\Livewire\Admin\Repairs;

use App\Actions\Repairs\AssignRepair;
use App\Actions\Repairs\CreateRepair;
use App\Actions\Repairs\UpdateRepair;
use App\Enums\RepairCoverage;
use App\Enums\RepairDeviceType;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Forms\Admin\Repairs\RepairForm;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Repair;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Take a device in / edit a repair (plan 24 B2). Intake data, device,
 * coverage (warranty needs a reference), diagnosis / work / parts on edit,
 * and the amounts only for users with repairs.costs (the form never holds
 * them otherwise). The customer is picked from the customers the user may
 * see: engineers do not create customers (owner decision).
 *
 * The engineer is picked on create only: the Admin picks anyone, an engineer
 * only themselves (default). Later changes go through take / release / assign
 * on the repair page. Status changes are never made here.
 */
#[Layout('layouts::admin')]
class Form extends Component
{
    use HandlesActionErrors;

    #[Locked]
    public ?Repair $repair = null;

    /**
     * Marker of the row this edit form was loaded from (UpdateRepair::version()).
     */
    #[Locked]
    public string $version = '';

    public RepairForm $form;

    public string $customerSearch = '';

    public function mount(?Repair $repair = null): void
    {
        if ($repair?->exists) {
            $this->authorize('update', $repair);

            if ($repair->status->isClosed()) {
                $this->redirectRoute('admin.repairs.show', $repair, navigate: true);

                return;
            }

            $this->repair = $repair;
            $this->form->setRepair($repair, $this->user());
            $this->version = UpdateRepair::version(Repair::query()->findOrFail($repair->id));

            return;
        }

        $this->authorize('create', Repair::class);

        $user = $this->user();
        $this->form->locale = (string) config('app.locale');

        if (array_key_exists($user->id, $this->assigneeOptions) && ! $user->can('repairs.assign')) {
            $this->form->assigned_to_id = (string) $user->id;
        }
    }

    /**
     * Every later request: the repair may have been reassigned or closed meanwhile.
     */
    public function hydrate(): void
    {
        $this->repair !== null
            ? $this->authorize('update', $this->repair)
            : $this->authorize('create', Repair::class);
    }

    /**
     * Whether this user's form carries the amounts (repairs.costs, and the
     * right to edit an existing repair).
     */
    #[Computed]
    public function canCosts(): bool
    {
        $user = $this->user();

        return $this->repair !== null ? $user->can('editCosts', $this->repair) : $user->can('repairs.costs');
    }

    /**
     * Engineer choices on create: id => name.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function assigneeOptions(): array
    {
        $user = $this->user();

        if ($this->repair !== null) {
            return [];
        }

        if ($user->can('repairs.assign')) {
            return app(AssignRepair::class)->candidates()->pluck('name', 'id')->all();
        }

        return $user->can('repairs.update') ? [$user->id => $user->name] : [];
    }

    /**
     * Customer picker results (customers the user may see).
     *
     * @return Collection<int, Customer>
     */
    #[Computed]
    public function customerResults(): Collection
    {
        if (mb_strlen(trim($this->customerSearch)) < 2) {
            return new Collection;
        }

        return Customer::query()
            ->visibleTo($this->user())
            ->search($this->customerSearch)
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'phone']);
    }

    #[Computed]
    public function customer(): ?Customer
    {
        if (! ctype_digit($this->form->customer_id)) {
            return null;
        }

        return Customer::query()->visibleTo($this->user())->find((int) $this->form->customer_id, ['id', 'name', 'phone']);
    }

    /**
     * Contacts of the chosen customer: id => "name · position".
     *
     * @return array<int, string>
     */
    #[Computed]
    public function contactOptions(): array
    {
        if ($this->customer === null) {
            return [];
        }

        return Contact::query()->where('customer_id', $this->customer->id)->orderByDesc('is_primary')->orderBy('name')->get(['id', 'name', 'position'])
            ->mapWithKeys(fn (Contact $contact): array => [$contact->id => trim($contact->name.($contact->position ? ' · '.$contact->position : ''))])
            ->all();
    }

    public function pickCustomer(int $id): void
    {
        $customer = Customer::query()->visibleTo($this->user())->find($id);

        if (! $customer instanceof Customer) {
            return;
        }

        $this->form->customer_id = (string) $customer->id;
        $this->form->contact_id = '';
        $this->form->locale = in_array($customer->locale, config('app.supported_locales'), true) ? $customer->locale : $this->form->locale;

        if (blank($this->form->contact_name)) {
            $this->form->contact_name = $customer->name;
        }

        if (blank($this->form->contact_phone)) {
            $this->form->contact_phone = (string) $customer->phone;
        }

        if (blank($this->form->company)) {
            $this->form->company = $customer->name;
        }

        $this->customerSearch = '';
        unset($this->customer, $this->customerResults, $this->contactOptions);
    }

    public function clearCustomer(): void
    {
        $this->form->customer_id = '';
        $this->form->contact_id = '';
        unset($this->customer, $this->contactOptions);
    }

    /**
     * A chosen contact fills the name and phone of the person at the counter.
     */
    public function updatedFormContactId(): void
    {
        $customerId = $this->customer?->id;
        $contact = $customerId !== null && ctype_digit($this->form->contact_id)
            ? Contact::query()->where('customer_id', $customerId)->find((int) $this->form->contact_id)
            : null;

        if ($contact instanceof Contact) {
            $this->form->contact_name = $contact->name;
            $this->form->contact_phone = (string) ($contact->phone ?: $this->form->contact_phone);
        }
    }

    public function save(CreateRepair $createRepair, UpdateRepair $updateRepair): void
    {
        $user = $this->user();

        if ($this->repair !== null) {
            $this->authorize('update', $this->repair);
        } else {
            $this->authorize('create', Repair::class);
        }

        $this->form->validate();

        if ($this->repair === null && filled($this->form->assigned_to_id) && ! array_key_exists((int) $this->form->assigned_to_id, $this->assigneeOptions)) {
            $this->addError('form.assigned_to_id', __('repairs.errors.assignee_invalid'));

            return;
        }

        $data = $this->form->repairData($this->canCosts);

        if ($this->repair === null) {
            $repair = $this->runFormAction(fn (): Repair => $createRepair->handle($data, $user));

            session()->flash('toast', ['title' => __('admin.toast.saved'), 'body' => __('repairs.toast.created', ['number' => $repair->number]), 'type' => 'success']);
            $this->redirectRoute('admin.repairs.show', $repair, navigate: true);

            return;
        }

        $repair = $this->runFormAction(fn (): Repair => $updateRepair->handle($this->repair, $data, $user, $this->version));

        session()->flash('toast', ['title' => __('admin.toast.saved'), 'body' => __('repairs.toast.saved', ['number' => $repair->number]), 'type' => 'success']);
        $this->redirectRoute('admin.repairs.show', $repair, navigate: true);
    }

    public function render(): View
    {
        $title = $this->repair ? __('repairs.edit_title', ['number' => $this->repair->number]) : __('repairs.new');

        return view('livewire.admin.repairs.form', [
            'title' => $title,
            'deviceTypes' => collect(RepairDeviceType::cases())->mapWithKeys(fn (RepairDeviceType $case): array => [$case->value => $case->label()])->all(),
            'coverages' => collect(RepairCoverage::cases())->mapWithKeys(fn (RepairCoverage $case): array => [$case->value => $case->label()])->all(),
            'locales' => collect(config('app.supported_locales'))->mapWithKeys(fn (string $locale): array => [$locale => __('repairs.locales.'.$locale)])->all(),
        ])->title($title);
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
