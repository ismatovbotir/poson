<?php

namespace App\Livewire\Admin\Leads;

use App\Actions\Leads\AssignLead;
use App\Actions\Leads\CreateLead;
use App\Actions\Leads\UpdateLead;
use App\Enums\LeadSource;
use App\Enums\LeadType;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Forms\Admin\Leads\LeadForm;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Product;
use App\Models\User;
use App\Support\Phone;
use App\Support\Search;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Create / edit a lead in the admin (plan 05 T3): manual sources (phone,
 * manual, telegram), the types the user works with (LeadPolicy::useType),
 * contact data, an estimate, products from a search picker, and a live
 * duplicate / customer hint on the phone.
 *
 * The manager is picked on create only, per permission: anyone eligible
 * with leads.assign, another sales user with leads.reassign (sales types),
 * otherwise "me" or unassigned. Later changes happen on the lead page.
 */
#[Layout('layouts::admin')]
class Form extends Component
{
    use HandlesActionErrors;

    #[Locked]
    public ?Lead $lead = null;

    public LeadForm $form;

    public string $productSearch = '';

    /**
     * Recent same-phone leads listed in the duplicate warning.
     */
    public const DUPLICATES_SHOWN = 5;

    public function mount(?Lead $lead = null): void
    {
        if ($lead?->exists) {
            $this->authorize('update', $lead);
            $this->lead = $lead;
            $this->form->setLead($lead);

            return;
        }

        $this->authorize('create', Lead::class);

        $user = $this->user();
        $this->form->source = LeadSource::Phone->value;
        $this->form->type = ($user->can('leads.sales') ? LeadType::Callback : LeadType::ServiceRequest)->value;
        $this->form->manager_id = (string) $user->id;
    }

    /**
     * A new type may rule out the chosen manager: fall back to "me" when allowed, else unassigned.
     */
    public function updatedFormType(): void
    {
        if ($this->lead === null && filled($this->form->manager_id) && ! array_key_exists((int) $this->form->manager_id, $this->managerOptions)) {
            $this->form->manager_id = array_key_exists($this->user()->id, $this->managerOptions) ? (string) $this->user()->id : '';
        }
    }

    /**
     * @return list<LeadType>
     */
    #[Computed]
    public function typeOptions(): array
    {
        $user = $this->user();

        return array_values(array_filter(
            LeadType::cases(),
            fn (LeadType $type): bool => ($type !== LeadType::Checkout || $this->lead?->type === LeadType::Checkout)
                && ($user->can('useType', [Lead::class, $type]) || $this->lead?->type === $type),
        ));
    }

    /**
     * @return list<LeadSource>
     */
    #[Computed]
    public function sourceOptions(): array
    {
        if ($this->lead !== null && ! in_array($this->lead->source, UpdateLead::MANUAL_SOURCES, true)) {
            return [$this->lead->source];
        }

        return UpdateLead::MANUAL_SOURCES;
    }

    /**
     * Manager choices on create: id => name.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function managerOptions(): array
    {
        $user = $this->user();
        $type = LeadType::tryFrom($this->form->type);

        if ($this->lead !== null || $type === null) {
            return [];
        }

        $probe = new Lead(['type' => $type]);
        $candidates = app(AssignLead::class)->candidates($probe, $user)->pluck('name', 'id')->all();

        if ($user->can($type->workPermission())) {
            $candidates[$user->id] = $user->name;
        }

        return $candidates;
    }

    /**
     * Picker results: products matching the search, not in the list yet.
     *
     * @return Collection<int, Product>
     */
    #[Computed]
    public function productResults(): Collection
    {
        if (mb_strlen(trim($this->productSearch)) < 2) {
            return new Collection;
        }

        $chosen = array_values(array_filter(array_column($this->form->items, 'product_id')));

        return Product::query()
            ->whereNotIn('id', $chosen)
            ->whereRaw("search_text like ? escape '!'", [Search::normalizedPattern($this->productSearch)])
            ->orderByDesc('is_active')
            ->orderBy('sku')
            ->limit(10)
            ->get(['id', 'sku', 'name', 'is_active']);
    }

    /**
     * Recent leads with the same phone (Lead::DUPLICATE_WINDOW_DAYS): the ones
     * the user can see, plus whether others exist that they can't.
     *
     * @return array{visible: Collection<int, Lead>, hidden: bool}
     */
    #[Computed]
    public function duplicates(): array
    {
        $phone = Phone::normalize($this->form->phone);

        if ($phone === null) {
            return ['visible' => new Collection, 'hidden' => false];
        }

        $recent = fn () => Lead::query()
            ->matchingPhone($phone)
            ->where('created_at', '>=', now()->subDays(Lead::DUPLICATE_WINDOW_DAYS))
            ->when($this->lead !== null, fn ($query) => $query->whereKeyNot($this->lead->id));

        $visible = $recent()->visibleTo($this->user());

        return [
            'visible' => (clone $visible)->with('manager:id,name')->latest('id')->limit(self::DUPLICATES_SHOWN)->get(),
            'hidden' => $recent()->count() > $visible->count(),
        ];
    }

    /**
     * The customer this phone matches (shown when the user may see customers).
     */
    #[Computed]
    public function matchedCustomer(): ?Customer
    {
        $phone = Phone::normalize($this->form->phone);

        if ($phone === null || ! $this->user()->can('viewAny', Customer::class)) {
            return null;
        }

        return CreateLead::matchCustomer($phone);
    }

    public function addProduct(int $productId): void
    {
        $product = Product::query()->find($productId, ['id', 'sku', 'name']);

        if (! $product instanceof Product || in_array($product->id, array_column($this->form->items, 'product_id'), true)) {
            return;
        }

        $this->form->items[] = [
            'id' => null,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'qty' => 1,
            'note' => '',
        ];

        $this->productSearch = '';
        unset($this->productResults);
    }

    public function removeItem(int $index): void
    {
        unset($this->form->items[$index]);
        $this->form->items = array_values($this->form->items);
    }

    public function save(CreateLead $createLead, UpdateLead $updateLead): void
    {
        $user = $this->user();
        $type = LeadType::tryFrom($this->form->type);

        if ($this->lead !== null) {
            $this->authorize('update', $this->lead);
        } else {
            $this->authorize('create', [Lead::class, $type]);
        }

        // A changed type must be one the user works with.
        if ($type !== null && $type !== $this->lead?->type) {
            $this->authorize('useType', [Lead::class, $type]);
        }

        $this->form->validate();

        if ($this->lead === null && filled($this->form->manager_id) && ! array_key_exists((int) $this->form->manager_id, $this->managerOptions)) {
            $this->addError('form.manager_id', __('crm.leads.errors.manager_invalid'));

            return;
        }

        $data = $this->form->leadData($user);

        if ($this->lead === null) {
            $lead = $this->runFormAction(fn (): Lead => $createLead->handle($data));

            session()->flash('toast', ['title' => __('admin.toast.saved'), 'body' => __('crm.leads.toast.created', ['reference' => $lead->reference()]), 'type' => 'success']);
            $this->redirectRoute('admin.leads.show', $lead, navigate: true);

            return;
        }

        $lead = $this->runFormAction(fn (): Lead => $updateLead->handle($this->lead, $data));

        session()->flash('toast', ['title' => __('admin.toast.saved'), 'body' => __('crm.leads.toast.saved', ['reference' => $lead->reference()]), 'type' => 'success']);
        $this->redirectRoute('admin.leads.show', $lead, navigate: true);
    }

    public function render(): View
    {
        $title = $this->lead ? __('crm.leads.edit_title', ['reference' => $this->lead->reference()]) : __('crm.leads.new');

        return view('livewire.admin.leads.form', [
            'title' => $title,
        ])->title($title);
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
