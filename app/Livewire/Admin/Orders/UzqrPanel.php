<?php

namespace App\Livewire\Admin\Orders;

use App\Actions\Payments\Uzqr\CancelUzqrInvoice;
use App\Actions\Payments\Uzqr\RefreshUzqrInvoice;
use App\Actions\Payments\Uzqr\ReleaseUzqrHold;
use App\Actions\Payments\Uzqr\ResolveUzqrUnmatched;
use App\Actions\Payments\Uzqr\StartUzqrPayment;
use App\Actions\Payments\Uzqr\UzqrPaymentRefused;
use App\Enums\PaymentMode;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Models\Order;
use App\Models\User;
use App\Models\UzqrTransaction;
use App\Services\Payments\Uzqr\UzqrConfig;
use App\Support\Admin\PasswordConfirmation;
use App\Support\Qr\QrRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Dynamic UzQR sub-panel of the order page (plan 11e §3, conditions 39, 45,
 * 49, 50): the order's invoices (status, amount, card type + last 4, errors —
 * never payer phone), "Check status now" (payments.view, 6 a minute per
 * user), "Cancel QR" (orders.update + payments.view), "Test payment" for a
 * TEST order (payments.test, test mode with a separate test host; Livewire
 * POST with CSRF, never GET) and the Admin resolution of an unmatched
 * payment, and the Admin "Release hold" of an expired invoice the bank never
 * confirmed (conditions 57–66: Admin role, recent password, 6 a minute,
 * required reason). Every action re-reads the order from the locked id and
 * authorizes.
 */
class UzqrPanel extends Component
{
    use HandlesActionErrors;

    /**
     * Most invoices of one order a "Check status now" asks the bank about.
     */
    public const CHECK_ROWS = 3;

    #[Locked]
    public int $orderId;

    #[Locked]
    public ?int $resolvingId = null;

    public string $resolution = '';

    public string $resolutionReference = '';

    public string $resolutionNote = '';

    #[Locked]
    public ?int $releasingId = null;

    public string $releaseNote = '';

    public function mount(int $orderId): void
    {
        $this->orderId = $orderId;
        $this->authorize('viewPayments', $this->order());
    }

    public function hydrate(): void
    {
        $this->authorize('viewPayments', $this->order());
    }

    public function checkStatus(RefreshUzqrInvoice $refresh): void
    {
        $order = $this->order();
        $this->authorize('checkUzqr', $order);

        if (! $this->throttle('uzqr-admin-check:'.$this->user()->id, (int) config('uzqr.limits.admin_check_per_minute', 6))) {
            return;
        }

        // Every row the bank may still change (newest first, at most CHECK_ROWS), incl. a released hold past its re-check window.
        $transactions = UzqrTransaction::query()->where('order_id', $order->id)->checkable()->latest('id')->limit(self::CHECK_ROWS)->get();

        if ($transactions->isEmpty()) {
            $this->dispatch('toast', title: __('uzqr.admin.nothing_open'), type: 'info');

            return;
        }

        $latest = $transactions->map(fn (UzqrTransaction $transaction): UzqrTransaction => $refresh->handle($transaction, 'admin'))->first();

        $this->dispatch('toast', title: __('uzqr.admin.checked'), body: __('uzqr.admin.status_now', ['status' => $latest->status->label()]), type: 'success');
    }

    public function cancelQr(CancelUzqrInvoice $cancel): void
    {
        $order = $this->order();
        $this->authorize('cancelUzqr', $order);

        if (! $this->throttle('uzqr-admin-check:'.$this->user()->id, (int) config('uzqr.limits.admin_check_per_minute', 6))) {
            return;
        }

        $transaction = $this->holding($order);

        if ($transaction === null || $transaction->invoice_id === null) {
            $this->dispatch('toast', title: __('uzqr.admin.nothing_open'), type: 'info');

            return;
        }

        try {
            $transaction = $cancel->handle($transaction, $this->user());
        } catch (UzqrPaymentRefused $refused) {
            $this->dispatch('toast', title: __('admin.toast.error'), body: $refused->userMessage(), type: 'error');

            return;
        }

        $this->dispatch('toast',
            title: $transaction->holdsOrder() ? __('uzqr.admin.cancel_requested') : __('admin.toast.done'),
            body: __('uzqr.admin.status_now', ['status' => $transaction->status->label()]),
            type: $transaction->holdsOrder() ? 'info' : 'success',
        );
    }

    /**
     * Show a test QR for a TEST order (condition 34/45).
     */
    public function testPay(StartUzqrPayment $start, UzqrConfig $config): void
    {
        $order = $this->order();
        $this->authorize('testPayUzqr', $order);
        // Admins pass Gate::before: the TEST-order rule is enforced here too (condition 45).
        abort_unless($order->is_test && $config->testAvailable(), 404);

        if (! $this->throttle('uzqr-admin-test:'.$this->user()->id, (int) config('uzqr.limits.open_per_ip_minute', 10))) {
            return;
        }

        try {
            $start->handle($order, $this->user());
        } catch (UzqrPaymentRefused $refused) {
            $this->dispatch('toast', title: __('admin.toast.error'), body: $refused->userMessage(), type: 'error');
        }
    }

    public function startResolve(int $transactionId): void
    {
        $order = $this->order();
        $this->authorize('resolveUzqr', $order);

        $transaction = UzqrTransaction::query()->where('order_id', $order->id)->whereKey($transactionId)->unresolvedUnmatched()->firstOrFail();

        $this->resolvingId = $transaction->id;
        $this->reset('resolution', 'resolutionReference', 'resolutionNote');
        $this->resetErrorBag();
    }

    public function cancelResolve(): void
    {
        $this->resolvingId = null;
        $this->reset('resolution', 'resolutionReference', 'resolutionNote');
    }

    public function resolve(ResolveUzqrUnmatched $resolve): void
    {
        $order = $this->order();
        $this->authorize('resolveUzqr', $order);

        $transaction = UzqrTransaction::query()->where('order_id', $order->id)->whereKey((int) $this->resolvingId)->firstOrFail();

        $resolve->handle($transaction, $this->resolution, $this->resolutionReference, $this->resolutionNote, $this->user());

        $this->cancelResolve();
        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('uzqr.admin.resolved'), type: 'success');
        $this->dispatch('$refresh')->to(Show::class);
    }

    /**
     * Open the "Release hold" form (conditions 57, 58): Admin, recent password confirmation.
     */
    public function startRelease(int $transactionId): void
    {
        $order = $this->order();
        $this->authorize('releaseUzqrHold', $order);

        if (! $this->passwordConfirmed($order)) {
            return;
        }

        $transaction = UzqrTransaction::query()->where('order_id', $order->id)->whereKey($transactionId)->firstOrFail();

        if (! $transaction->isReleasable()) {
            $this->dispatch('toast', title: __('admin.toast.error'), body: __('uzqr.errors.not_releasable'), type: 'error');

            return;
        }

        $this->releasingId = $transaction->id;
        $this->releaseNote = '';
        $this->resetErrorBag();
    }

    public function cancelRelease(): void
    {
        $this->releasingId = null;
        $this->releaseNote = '';
    }

    /**
     * Release the hold (conditions 57–63); the bank is asked first, so the outcome may be settled / closed instead.
     */
    public function release(ReleaseUzqrHold $release): void
    {
        $order = $this->order();
        $this->authorize('releaseUzqrHold', $order);

        if (! $this->passwordConfirmed($order)) {
            return;
        }

        $this->validate(['releaseNote' => ['required', 'string', 'max:'.ReleaseUzqrHold::NOTE_MAX]], attributes: ['releaseNote' => __('uzqr.admin.note')]);

        if (! $this->throttle('uzqr-admin-check:'.$this->user()->id, (int) config('uzqr.limits.admin_check_per_minute', 6))) {
            return;
        }

        $transaction = UzqrTransaction::query()->where('order_id', $order->id)->whereKey((int) $this->releasingId)->firstOrFail();

        $outcome = $release->handle($transaction, $this->releaseNote, $this->user());

        $this->cancelRelease();
        $this->dispatch('toast',
            title: $outcome === 'released' ? __('admin.toast.done') : __('uzqr.admin.checked'),
            body: __('uzqr.admin.'.match ($outcome) {
                'released' => 'released',
                'settled' => 'release_settled',
                'unmatched' => 'release_unmatched',
                default => 'release_closed',
            }),
            type: $outcome === 'unmatched' ? 'error' : 'success',
        );
        $this->dispatch('$refresh')->to(Show::class);
    }

    public function render(UzqrConfig $config, QrRenderer $renderer): View
    {
        $order = $this->order();
        $user = $this->user();
        $transactions = UzqrTransaction::query()->with('resolvedBy:id,name')->where('order_id', $order->id)->latest('id')->limit(20)->get();
        $open = $transactions->first(fn (UzqrTransaction $transaction): bool => $transaction->holdsOrder());
        $qr = null;

        // The QR itself only for TEST orders (staff test payments); real customers see it on /o.
        if ($order->is_test && $open !== null && $open->qr_text !== null && $open->status->isOpen() && $user->can('testPayUzqr', $order)) {
            $qr = $renderer->svgDataUri($open->qr_text);
        }

        return view('livewire.admin.orders.uzqr-panel', [
            'order' => $order,
            'transactions' => $transactions,
            'open' => $open,
            'checkable' => $transactions->contains(fn (UzqrTransaction $transaction): bool => $transaction->isCheckable()),
            'qr' => $qr,
            'canCheck' => $user->can('checkUzqr', $order),
            'canCancel' => $user->can('cancelUzqr', $order),
            'canResolve' => $user->can('resolveUzqr', $order),
            'canRelease' => $user->can('releaseUzqrHold', $order),
            'canTestPay' => $user->can('testPayUzqr', $order) && $config->testAvailable() && $config->activeMode() === PaymentMode::Test,
        ]);
    }

    protected function order(): Order
    {
        return Order::query()->findOrFail($this->orderId);
    }

    /**
     * Server-side recent password confirmation (condition 58), else off to the confirm page.
     */
    protected function passwordConfirmed(Order $order): bool
    {
        if (PasswordConfirmation::isRecent()) {
            return true;
        }

        $this->redirect(PasswordConfirmation::url(route('admin.orders.show', $order)), navigate: false);

        return false;
    }

    protected function holding(Order $order): ?UzqrTransaction
    {
        return UzqrTransaction::query()->where('order_id', $order->id)->holding()->latest('id')->first();
    }

    protected function throttle(string $key, int $max): bool
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            $this->dispatch('toast', title: __('admin.toast.error'), body: __('uzqr.errors.too_many', ['seconds' => RateLimiter::availableIn($key)]), type: 'error');

            return false;
        }

        RateLimiter::hit($key, 60);

        return true;
    }

    protected function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
