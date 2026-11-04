<?php

namespace App\Livewire\Admin\Customers;

use App\Actions\Customers\DeleteCustomer;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Models\Activity;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\TelegramUser;
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
 * colleagues have), history (activities), quotes and orders (later). The
 * "Telegram" block lists linked bot accounts for telegram.inbox holders (plan 21
 * A7, T21-18): no message text, no telegram_id, never held in component state.
 */
#[Layout('layouts::admin')]
class Show extends Component
{
    use HandlesActionErrors;

    public const TABS = ['leads', 'history', 'quotes', 'orders'];

    public const LEADS_SHOWN = 50;

    public const HISTORY_SHOWN = 200;

    public const TELEGRAM_SHOWN = 10;

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

    /**
     * The customer's quotes the user may see (Quote::visibleTo()).
     *
     * @return Collection<int, Quote>
     */
    #[Computed]
    public function quotes(): Collection
    {
        return Quote::query()->visibleTo($this->user())->where('customer_id', $this->customer->id)->latest('id')->limit(self::LEADS_SHOWN)->get();
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
            'canCreateQuote' => $user->can('create', [Quote::class, $this->customer]),
            'telegramAccounts' => $this->telegramAccounts($user),
        ])->title($this->customer->name);
    }

    /**
     * Registered accounts linked to this customer (the registered-only rule of
     * isVisibleTo, T21-25). A row the user may not view is shown without name or
     * thread link; the view decides per row with can('view').
     *
     * @return Collection<int, TelegramUser>
     */
    private function telegramAccounts(User $user): Collection
    {
        if (! $user->can('telegram.inbox')) {
            return new Collection;
        }

        return $this->customer->telegramUsers()
            ->registered()
            ->latest('id')
            ->limit(self::TELEGRAM_SHOWN)
            ->get(['id', 'customer_id', 'username', 'name', 'first_name', 'last_name', 'manager_id', 'registered_at', 'link_verified_at']);
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
