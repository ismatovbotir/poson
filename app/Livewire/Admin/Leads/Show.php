<?php

namespace App\Livewire\Admin\Leads;

use App\Actions\Customers\ConvertLeadToCustomer;
use App\Actions\Customers\FindCustomerMatches;
use App\Actions\Leads\AddLeadActivity;
use App\Actions\Leads\AssignLead;
use App\Actions\Leads\ChangeLeadStatus;
use App\Actions\Leads\DeleteLead;
use App\Data\Customers\CustomerData;
use App\Data\Customers\CustomerMatch;
use App\Enums\ActivityType;
use App\Enums\CustomerType;
use App\Enums\LeadLostReason;
use App\Enums\LeadStatus;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Models\Activity;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Lead page (plan 05 T3): status control (lost asks for a reason in a
 * modal), take / release / assign, contact, items (linked to the admin
 * product), attribution, duplicate and customer badges, the activity
 * timeline and "add note" / "log call". Read-only for users who may only
 * view the lead (a sales manager on a service request).
 *
 * Every write authorizes against LeadPolicy and goes through an action.
 */
#[Layout('layouts::admin')]
class Show extends Component
{
    use HandlesActionErrors;

    /**
     * Timeline entries shown.
     */
    public const TIMELINE_LIMIT = 200;

    public Lead $lead;

    public string $lostReason = '';

    public string $lostNote = '';

    public string $activityType = 'note';

    public string $activityBody = '';

    public string $assignTo = '';

    /**
     * Conversion target: '' (not chosen), 'new', or a customer id.
     */
    public string $convertTarget = '';

    /**
     * The conversion modal is open (entangled with Alpine); matches are only looked up then.
     */
    public bool $convertOpen = false;

    public string $convertType = 'company';

    public string $convertName = '';

    public string $convertInn = '';

    public function mount(Lead $lead): void
    {
        $this->authorize('view', $lead);
        $this->lead = $lead;
        $this->assignTo = (string) $lead->manager_id;
    }

    /**
     * Every later request: the lead may have been reassigned meanwhile.
     */
    public function hydrate(): void
    {
        $this->authorize('view', $this->lead);
    }

    public function changeStatus(string $status, ChangeLeadStatus $changeStatus): void
    {
        $this->authorize('update', $this->lead);

        $to = LeadStatus::tryFrom($status);

        // Lost goes through markLost (reason); converted only through the conversion (5b).
        if ($to === null || $to === LeadStatus::Lost || ! $to->isManual()) {
            return;
        }

        if ($this->runToastAction(fn () => $changeStatus->handle($this->lead, $to, $this->user()))) {
            $this->refreshLead();
            $this->dispatch('toast', title: __('admin.toast.saved'), body: __('crm.leads.toast.status', ['status' => $to->label()]));
        }
    }

    public function markLost(ChangeLeadStatus $changeStatus): void
    {
        $this->authorize('update', $this->lead);

        $this->validate([
            'lostReason' => ['required', Rule::enum(LeadLostReason::class)],
            'lostNote' => [Rule::requiredIf($this->lostReason === LeadLostReason::Other->value), 'nullable', 'string', 'max:'.ChangeLeadStatus::NOTE_MAX],
        ], attributes: [
            'lostReason' => __('crm.leads.fields.lost_reason'),
            'lostNote' => __('crm.leads.fields.lost_note'),
        ]);

        $this->runMapped(
            fn () => $changeStatus->handle($this->lead, LeadStatus::Lost, $this->user(), LeadLostReason::from($this->lostReason), $this->lostNote),
            ['status' => 'lostReason', 'lost_reason' => 'lostReason', 'lost_note' => 'lostNote'],
        );

        $this->reset('lostReason', 'lostNote');
        $this->refreshLead();
        $this->dispatch('lead-lost-saved');
        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('crm.leads.toast.status', ['status' => LeadStatus::Lost->label()]));
    }

    public function take(AssignLead $assignLead): void
    {
        $this->authorize('take', $this->lead);

        if ($this->runToastAction(fn () => $assignLead->take($this->lead, $this->user()))) {
            $this->refreshLead();
            $this->dispatch('toast', title: __('admin.toast.done'), body: __('crm.leads.toast.taken', ['reference' => $this->lead->reference()]));
        }
    }

    public function release(AssignLead $assignLead): void
    {
        $this->authorize('release', $this->lead);

        if ($this->runToastAction(fn () => $assignLead->release($this->lead, $this->user()))) {
            $this->refreshLead();
            $this->afterHandOff(__('admin.toast.done'), __('crm.leads.toast.released', ['reference' => $this->lead->reference()]));
        }
    }

    public function assign(AssignLead $assignLead): void
    {
        $this->authorize('assign', $this->lead);

        $managerId = ctype_digit($this->assignTo) ? (int) $this->assignTo : null;

        if ($this->runToastAction(fn () => $assignLead->assign($this->lead, $managerId, $this->user()))) {
            $this->refreshLead();
            $this->afterHandOff(__('admin.toast.saved'), __('crm.leads.toast.assigned', ['name' => $this->lead->manager?->name ?? __('crm.leads.unassigned')]));
        }
    }

    /**
     * Prefill the conversion modal: one INN match or a single match is
     * preselected; several matches make the manager pick; none → new.
     */
    public function openConvert(): void
    {
        $this->authorize('convert', $this->lead);

        $data = ConvertLeadToCustomer::dataFromLead($this->lead);
        $this->convertType = $data->type->value;
        $this->convertName = $data->name;
        $this->convertInn = (string) $data->inn;

        $this->convertOpen = true;
        unset($this->customerMatches);
        $matches = $this->customerMatches;
        $innMatch = collect($matches)->first(fn (CustomerMatch $match): bool => $match->reason === CustomerMatch::INN);

        $this->convertTarget = match (true) {
            $innMatch !== null => (string) $innMatch->customer->id,
            count($matches) === 1 => (string) $matches[0]->customer->id,
            $matches === [] && $this->user()->can('create', Customer::class) => 'new',
            default => '',
        };

        $this->resetValidation();
        $this->dispatch('lead-convert-open');
    }

    public function convert(ConvertLeadToCustomer $convertLead): void
    {
        $this->authorize('convert', $this->lead);

        $this->validate([
            'convertTarget' => ['required', 'string', 'max:20'],
        ], ['convertTarget.required' => __('crm.customers.errors.pick_target')]);

        $customer = null;
        $newCustomer = null;

        if ($this->convertTarget === 'new') {
            $this->authorize('create', Customer::class);

            $this->validate([
                'convertType' => ['required', Rule::enum(CustomerType::class)],
                'convertName' => ['required', 'string', 'max:255'],
                'convertInn' => ['nullable', 'digits:9'],
            ], attributes: [
                'convertType' => __('crm.customers.fields.type'),
                'convertName' => __($this->convertType === CustomerType::Individual->value ? 'crm.customers.fields.full_name' : 'crm.customers.fields.name'),
                'convertInn' => __('crm.customers.fields.inn'),
            ]);

            $prefill = ConvertLeadToCustomer::dataFromLead($this->lead);
            $newCustomer = new CustomerData(
                type: CustomerType::from($this->convertType),
                name: $this->convertName,
                inn: $this->convertInn,
                phone: $prefill->phone,
                email: $prefill->email,
                locale: $prefill->locale,
            );
        } else {
            $customer = ctype_digit($this->convertTarget) ? Customer::query()->find((int) $this->convertTarget) : null;

            if (! $customer instanceof Customer) {
                $this->addError('convertTarget', __('crm.customers.errors.pick_target'));

                return;
            }

            $this->authorize('view', $customer);
        }

        $result = $this->runMapped(
            fn (): Customer => $convertLead->handle($this->lead, $customer, $this->user(), $newCustomer),
            ['name' => 'convertName', 'inn' => 'convertInn', 'type' => 'convertType', 'status' => 'convertTarget', 'phone' => 'convertTarget', 'manager_id' => 'convertTarget', 'contacts' => 'convertTarget', 'contacts.0.phone' => 'convertTarget', 'contacts.0.name' => 'convertTarget'],
        );

        $this->refreshLead();
        $this->convertOpen = false;
        $this->dispatch('lead-convert-saved');
        $this->dispatch('toast', title: __('admin.toast.done'), body: __('crm.customers.toast.converted', ['reference' => $this->lead->reference(), 'name' => $result->name]));
    }

    /**
     * Customers this lead may belong to (INN first, then phones).
     *
     * @return list<CustomerMatch>
     */
    #[Computed]
    public function customerMatches(): array
    {
        if (! $this->convertOpen || ! $this->user()->can('convert', $this->lead)) {
            return [];
        }

        return app(FindCustomerMatches::class)->handle($this->lead->phone, $this->lead->inn);
    }

    public function addActivity(AddLeadActivity $addActivity): void
    {
        $this->authorize('update', $this->lead);

        $this->validate([
            'activityType' => ['required', Rule::in(array_map(fn (ActivityType $type): string => $type->value, ActivityType::manual()))],
            'activityBody' => ['required', 'string', 'max:'.AddLeadActivity::BODY_MAX],
        ], attributes: [
            'activityType' => __('crm.leads.activity.type'),
            'activityBody' => __('crm.leads.activity.body'),
        ]);

        $this->runMapped(
            fn () => $addActivity->handle($this->lead, ActivityType::from($this->activityType), $this->activityBody, $this->user()),
            ['activity_type' => 'activityType', 'activity_body' => 'activityBody'],
        );

        $this->reset('activityBody');
        unset($this->activities);
        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('crm.leads.toast.activity_added'));
    }

    public function delete(DeleteLead $deleteLead): void
    {
        $this->authorize('delete', $this->lead);

        $reference = $this->lead->reference();
        $deleteLead->handle($this->lead);

        session()->flash('toast', ['title' => __('admin.toast.deleted'), 'body' => $reference, 'type' => 'success']);
        $this->redirectRoute('admin.leads.index', navigate: true);
    }

    /**
     * @return Collection<int, Activity>
     */
    #[Computed]
    public function activities(): Collection
    {
        return $this->lead->activities()
            ->with('user:id,name')
            ->latest('created_at')
            ->latest('id')
            ->limit(self::TIMELINE_LIMIT)
            ->get();
    }

    /**
     * Manager choices for "assign" (empty without leads.assign / leads.reassign).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function candidates(): array
    {
        if (! $this->user()->can('assign', $this->lead)) {
            return [];
        }

        return app(AssignLead::class)->candidates($this->lead, $this->user())->pluck('name', 'id')->all();
    }

    public function render(): View
    {
        $user = $this->user();
        $this->lead->loadMissing(['items.product', 'manager:id,name', 'customer:id,name,manager_id', 'createdBy:id,name', 'duplicateOf']);

        $duplicateOf = $this->lead->duplicateOf;

        return view('livewire.admin.leads.show', [
            'canEdit' => $user->can('update', $this->lead),
            'canTake' => $user->can('take', $this->lead),
            'canRelease' => $user->can('release', $this->lead),
            'canAssign' => $user->can('assign', $this->lead),
            'canDelete' => $user->can('delete', $this->lead),
            'canConvert' => $user->can('convert', $this->lead),
            'canCreateCustomer' => $user->can('create', Customer::class),
            'canViewCustomer' => $this->lead->customer !== null && $user->can('view', $this->lead->customer),
            'canViewProducts' => $user->can('products.view'),
            'duplicateOfVisible' => $duplicateOf !== null && $user->can('view', $duplicateOf),
            'laterDuplicates' => $this->lead->duplicates()->visibleTo($user)->latest('id')->limit(10)->get(['id', 'status', 'created_at']),
            'statuses' => array_values(array_filter(LeadStatus::cases(), fn (LeadStatus $status): bool => $status->isManual() && $this->lead->status->canTransitionTo($status))),
        ])->title($this->lead->reference().' · '.($this->lead->company ?: $this->lead->name));
    }

    /**
     * Run an action; its error keys are renamed to this component's properties.
     *
     * @param  array<string, string>  $keys  action key => property
     *
     * @throws ValidationException
     */
    private function runMapped(callable $callback, array $keys): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => [$keys[$key] ?? $key => $messages])->all(),
            );
        }
    }

    /**
     * Toast here, or back to the list (toast flashed) when the user may no longer see the lead.
     */
    private function afterHandOff(string $title, string $body): void
    {
        if ($this->user()->can('view', $this->lead)) {
            $this->dispatch('toast', title: $title, body: $body);

            return;
        }

        session()->flash('toast', ['title' => $title, 'body' => $body, 'type' => 'success']);
        $this->redirectRoute('admin.leads.index', navigate: true);
    }

    private function refreshLead(): void
    {
        $this->lead->refresh();
        $this->assignTo = (string) $this->lead->manager_id;
        unset($this->activities, $this->candidates, $this->customerMatches);
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
