<?php

namespace App\Livewire\Admin\Quotes;

use App\Actions\Quotes\ChangeQuoteStatus;
use App\Actions\Quotes\DeleteQuote;
use App\Actions\Quotes\DuplicateQuote;
use App\Enums\QuoteStatus;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Models\Activity;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Quote page (plan 06): lines and totals (VAT included), customer and lead,
 * status actions (mark sent, accepted, rejected, expired), duplicate,
 * delete (draft), Download PDF and Copy public link (both mark a draft as
 * sent), and the history. Access is re-checked on every request.
 */
#[Layout('layouts::admin')]
class Show extends Component
{
    use HandlesActionErrors;

    public Quote $quote;

    public function mount(Quote $quote): void
    {
        $this->authorize('view', $quote);
        $this->quote = $quote;
    }

    public function hydrate(): void
    {
        $this->authorize('view', $this->quote);
    }

    public function markSent(ChangeQuoteStatus $changeStatus): void
    {
        $this->authorize('changeStatus', $this->quote);

        if ($this->runToastAction(fn () => $changeStatus->handle($this->quote, QuoteStatus::Sent, $this->user(), 'manual'))) {
            $this->refreshQuote();
            $this->dispatch('toast', title: __('admin.toast.saved'), body: __('crm.quotes.toast.status', ['status' => QuoteStatus::Sent->label()]));
        }
    }

    /**
     * The public link for the clipboard; a draft becomes "sent" (the customer is about to get it).
     */
    public function copyLink(ChangeQuoteStatus $changeStatus): string
    {
        $this->authorize('changeStatus', $this->quote);

        $changeStatus->markSent($this->quote, $this->user(), 'link');
        $this->refreshQuote();
        $this->dispatch('toast', title: __('admin.toast.done'), body: __('crm.quotes.toast.link_copied'));

        return route('quotes.public', $this->quote->ulid);
    }

    public function changeStatus(string $status, ChangeQuoteStatus $changeStatus): void
    {
        $this->authorize('changeStatus', $this->quote);

        $to = QuoteStatus::tryFrom($status);

        if ($to === null || ! in_array($to, QuoteStatus::closing(), true)) {
            return;
        }

        if ($this->runToastAction(fn () => $changeStatus->handle($this->quote, $to, $this->user()))) {
            $this->refreshQuote();
            $this->dispatch('toast', title: __('admin.toast.saved'), body: __('crm.quotes.toast.status', ['status' => $to->label()]));
        }
    }

    public function duplicate(DuplicateQuote $duplicateQuote): void
    {
        $this->authorize('duplicate', $this->quote);

        $copy = null;

        if (! $this->runToastAction(function () use ($duplicateQuote, &$copy): void {
            $copy = $duplicateQuote->handle($this->quote, $this->user());
        }) || $copy === null) {
            return;
        }

        session()->flash('toast', ['title' => __('admin.toast.saved'), 'body' => __('crm.quotes.toast.duplicated', ['number' => $copy->number]), 'type' => 'success']);
        $this->redirectRoute('admin.quotes.edit', $copy, navigate: true);
    }

    public function delete(DeleteQuote $deleteQuote): void
    {
        $this->authorize('delete', $this->quote);

        $number = $this->quote->number;

        if ($this->runToastAction(fn () => $deleteQuote->handle($this->quote, $this->user()))) {
            session()->flash('toast', ['title' => __('admin.toast.deleted'), 'body' => $number, 'type' => 'success']);
            $this->redirectRoute('admin.quotes.index', navigate: true);
        }
    }

    /**
     * @return Collection<int, Activity>
     */
    #[Computed]
    public function activities(): Collection
    {
        return $this->quote->activities()->with('user:id,name')->latest('created_at')->latest('id')->limit(200)->get();
    }

    public function render(): View
    {
        $user = $this->user();
        $this->quote->loadMissing(['items.product', 'customer.primaryContact', 'lead:id,name,company', 'manager:id,name']);

        return view('livewire.admin.quotes.show', [
            'canEdit' => $user->can('update', $this->quote),
            'canChangeStatus' => $user->can('changeStatus', $this->quote),
            'canDuplicate' => $user->can('duplicate', $this->quote),
            'canDelete' => $user->can('delete', $this->quote),
            'canViewCustomer' => $user->can('view', $this->quote->customer),
            'canViewLead' => $this->quote->lead !== null && $user->can('view', $this->quote->lead),
            'canViewProducts' => $user->can('products.view'),
            'closing' => array_values(array_filter(QuoteStatus::closing(), fn (QuoteStatus $status): bool => $this->quote->status->canTransitionTo($status))),
            'publicUrl' => $this->quote->status !== QuoteStatus::Draft ? route('quotes.public', $this->quote->ulid) : null,
        ])->title(__('crm.quotes.show_title', ['number' => $this->quote->number]));
    }

    private function refreshQuote(): void
    {
        $this->quote->refresh();
        unset($this->activities);
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
