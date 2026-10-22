<?php

namespace App\Livewire\Admin\Customers;

use App\Actions\Customers\DeleteCustomer;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Models\Activity;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Customer page (plan 05b T5): requisites, price level, manager, contacts,
 * and tabs: leads (only those Lead::visibleTo() allows, plus how many more
 * colleagues have), history (activities), quotes and orders (later).
 */
#[Layout('layouts::admin')]
class Show extends Component
{
    use HandlesActionErrors;

    public const TABS = ['leads', 'history', 'quotes', 'orders'];

    public const LEADS_SHOWN = 50;

    public const HISTORY_SHOWN = 200;

    public Customer $customer;

    #[Url(except: 'leads')]
    public string $tab = 'leads';

    public function mount(Customer $customer): void
    {
        $this->authorize('view', $customer);
        $this->customer = $customer;

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'leads';
        }
    }

    public function hydrate(): void
    {
        $this->authorize('view', $this->customer);
    }

    /**
     * @return Collection<int, Lead>
     */
    #[Computed]
    public function leads(): Collection
    {
        return $this->visibleLeads()->with(['manager:id,name', 'items'])->latest('id')->limit(self::LEADS_SHOWN)->get();
    }

    /**
     * Leads of this customer the user can't see (colleagues' leads).
     */
    #[Computed]
    public function hiddenLeads(): int
    {
        return max(0, Lead::query()->where('customer_id', $this->customer->id)->count() - $this->visibleLeads()->count());
    }

    /**
     * @return Collection<int, Activity>
     */
    #[Computed]
    public function activities(): Collection
    {
        return $this->customer->activities()->with('user:id,name')->latest('created_at')->latest('id')->limit(self::HISTORY_SHOWN)->get();
    }

    public function delete(DeleteCustomer $deleteCustomer): void
    {
        $this->authorize('delete', $this->customer);

        $name = $this->customer->name;

        if ($this->runToastAction(fn () => $deleteCustomer->handle($this->customer))) {
            session()->flash('toast', ['title' => __('admin.toast.deleted'), 'body' => $name, 'type' => 'success']);
            $this->redirectRoute('admin.customers.index', navigate: true);
        }
    }

    public function render(): View
    {
        $user = $this->user();
        $this->customer->loadMissing(['manager:id,name', 'contacts' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('id')]);

        return view('livewire.admin.customers.show', [
            'canEdit' => $user->can('update', $this->customer),
            'canDelete' => $user->can('delete', $this->customer),
        ])->title($this->customer->name);
    }

    /**
     * @return Builder<Lead>
     */
    private function visibleLeads(): Builder
    {
        return Lead::query()->visibleTo($this->user())->where('customer_id', $this->customer->id);
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
