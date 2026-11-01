<?php

use App\Actions\Orders\ChangeOrderPaymentMethod;
use App\Actions\Orders\ChangeOrderStatus;
use App\Actions\Orders\DeleteTestOrder;
use App\Actions\Orders\MarkOrderPaid;
use App\Actions\Orders\ReissueInvoice;
use App\Actions\Orders\ReleaseStalePaymentHolds;
use App\Actions\Orders\SetPaymentReconciled;
use App\Actions\Orders\UpdateOrderLines;
use App\Actions\Orders\VoidManualPayment;
use App\Actions\Payments\SettleOnlinePayment;
use App\Actions\Payments\Uzqr\ConfirmUzqrFiscalReceipt;
use App\Actions\Payments\Uzqr\ResolveUzqrUnmatched;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Enums\UzqrSettlement;
use App\Enums\UzqrTransactionStatus;
use App\Events\Orders\OrderPaid;
use App\Events\Uzqr\UzqrUnmatchedResolved;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\UzqrTransaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Support\Uzqr;

beforeEach(function () {
    Uzqr::configure();
    $this->admin = staffUser();
    Event::fake([OrderPaid::class, UzqrUnmatchedResolved::class]);
});

function heldOrder(UzqrTransactionStatus $status = UzqrTransactionStatus::Pending, ?Closure $state = null): Order
{
    $order = Uzqr::order(['payment_status' => PaymentStatus::Pending]);
    $factory = UzqrTransaction::factory();
    $factory = $state ? $state($factory) : $factory;
    $factory->create(['order_id' => $order->id, 'amount' => $order->total, 'status' => $status]);

    return $order;
}

it('holds the order while creating, pending or expired without bank confirmation', function (UzqrTransactionStatus $status, bool $closed, bool $holds) {
    $order = Uzqr::order();
    UzqrTransaction::factory()->create(['order_id' => $order->id, 'status' => $status, 'closed_at' => $closed ? now() : null]);

    expect($order->hasActiveTransaction())->toBe($holds)->and($order->isLinesEditable())->toBe(! $holds);
})->with([
    'creating' => [UzqrTransactionStatus::Creating, false, true],
    'pending' => [UzqrTransactionStatus::Pending, false, true],
    'expired unconfirmed' => [UzqrTransactionStatus::Expired, false, true],
    'expired confirmed' => [UzqrTransactionStatus::Expired, true, false],
    'failed' => [UzqrTransactionStatus::Failed, true, false],
    'create_failed' => [UzqrTransactionStatus::CreateFailed, true, false],
]);

it('rejects every order mutator while a QR is open', function () {
    $order = heldOrder();
    $order->forceFill(['payment_status' => PaymentStatus::Unpaid])->save();

    expect(fn () => app(UpdateOrderLines::class)->handle($order, [], $this->admin))->toThrow(ValidationException::class)
        ->and(fn () => app(ChangeOrderPaymentMethod::class)->handle($order, 'uzqr_static', $this->admin))->toThrow(ValidationException::class)
        ->and(fn () => app(MarkOrderPaid::class)->handle($order, manualPayment('uzqr_dynamic', $order->total), $this->admin))->toThrow(ValidationException::class)
        ->and(fn () => app(ChangeOrderStatus::class)->handle($order, OrderStatus::Cancelled, $this->admin, 'x'))->toThrow(ValidationException::class)
        ->and(fn () => app(ReissueInvoice::class)->handle($order, $this->admin))->toThrow(ValidationException::class);

    $payment = OrderPayment::factory()->create(['order_id' => $order->id, 'method' => PaymentMethod::UzqrStatic, 'status' => OrderPaymentStatus::Paid, 'amount' => $order->total]);
    expect(fn () => app(VoidManualPayment::class)->handle($order, $payment, 'mistake', $this->admin))->toThrow(ValidationException::class);
});

it('never lets ReleaseStalePaymentHolds touch a UzQR order', function () {
    $order = Uzqr::order(['payment_status' => PaymentStatus::Pending, 'payment_pending_at' => now()->subDay()]);

    expect(app(ReleaseStalePaymentHolds::class)->handle())->toBe(0)->and($order->refresh()->payment_status)->toBe(PaymentStatus::Pending);
});

it('refuses staff mark-paid for dynamic UzQR orders (bank-confirmed only)', function () {
    $order = Uzqr::order();

    expect(PaymentMethod::UzqrDynamic->isManual())->toBeFalse()
        ->and(fn () => app(MarkOrderPaid::class)->handle($order, manualPayment('uzqr_dynamic', $order->total), $this->admin))->toThrow(ValidationException::class);
});

it('deletes a TEST order with test-mode rows when nothing was paid', function () {
    config(['uzqr.base_urls.test' => Uzqr::TEST]);
    $order = Uzqr::order(test: true);
    UzqrTransaction::factory()->testMode()->failed()->create(['order_id' => $order->id]);

    app(DeleteTestOrder::class)->handle($order);

    expect(Order::query()->whereKey($order->id)->exists())->toBeFalse()->and(UzqrTransaction::count())->toBe(0);
});

it('hard-fails deleting a TEST order with prod rows, settled rows, open rows or a pay_id without a test host', function (Closure $make, bool $testHost) {
    Uzqr::configure('test', testHost: $testHost);
    $order = Uzqr::order(test: true);
    $make(UzqrTransaction::factory()->state(['order_id' => $order->id]));

    expect(fn () => app(DeleteTestOrder::class)->handle($order))->toThrow(ValidationException::class)
        ->and(Order::query()->whereKey($order->id)->exists())->toBeTrue();
})->with([
    'prod row' => [fn ($factory) => $factory->failed()->create(['mode' => PaymentMode::Prod]), true],
    'settled test row' => [fn ($factory) => $factory->testMode()->settled()->create(), true],
    'open test row' => [fn ($factory) => $factory->testMode()->create(), true],
    'pay_id without test host' => [fn ($factory) => $factory->testMode()->unmatched()->create(['settlement' => null]), false],
]);

it('lets an Admin apply an unmatched payment once the order can take it', function () {
    $order = Uzqr::order(['payment_status' => PaymentStatus::Pending, 'total' => 999]);
    $transaction = UzqrTransaction::factory()->unmatched('amount_mismatch')->create(['order_id' => $order->id, 'amount' => 1000]);

    expect(fn () => app(ResolveUzqrUnmatched::class)->handle($transaction, 'applied', null, 'checked', $this->admin))->toThrow(ValidationException::class);

    $order->forceFill(['total' => 1000])->save();
    $transaction = app(ResolveUzqrUnmatched::class)->handle($transaction, 'applied', null, 'Total fixed after call', $this->admin);

    expect($transaction->settlement)->toBe(UzqrSettlement::Settled)->and($transaction->resolved_by_id)->toBe($this->admin->id)
        ->and($order->refresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and(OrderPayment::query()->where('provider', 'uzqr')->where('provider_ref', $transaction->pay_id)->count())->toBe(1);
    Event::assertDispatched(UzqrUnmatchedResolved::class);
    Event::assertDispatched(OrderPaid::class);
});

it('records an external refund with a required reference and releases the order', function () {
    $order = Uzqr::order(['payment_status' => PaymentStatus::Pending]);
    $transaction = UzqrTransaction::factory()->unmatched('method_changed')->create(['order_id' => $order->id, 'amount' => $order->total]);

    expect(fn () => app(ResolveUzqrUnmatched::class)->handle($transaction, 'refunded_externally', '', 'returned', $this->admin))->toThrow(ValidationException::class)
        ->and(fn () => app(ResolveUzqrUnmatched::class)->handle($transaction, 'refunded_externally', 'R-1', '', $this->admin))->toThrow(ValidationException::class)
        ->and(fn () => app(ResolveUzqrUnmatched::class)->handle($transaction, 'whatever', 'R-1', 'x', $this->admin))->toThrow(ValidationException::class);

    $transaction = app(ResolveUzqrUnmatched::class)->handle($transaction, 'refunded_externally', 'BANK-REF-77', 'Returned in cabinet', $this->admin);

    expect($transaction->resolution)->toBe('refunded_externally')->and($transaction->resolution_reference)->toBe('BANK-REF-77')
        ->and($order->refresh()->payment_status)->toBe(PaymentStatus::Unpaid)
        ->and(fn () => app(ResolveUzqrUnmatched::class)->handle($transaction, 'applied', null, 'again', $this->admin))->toThrow(ValidationException::class);
});

it('puts settled UzQR payments on the unreconciled list and the fiscal receipt task', function () {
    $order = Uzqr::order(['payment_status' => PaymentStatus::Pending]);
    $transaction = UzqrTransaction::factory()->settled()->create(['order_id' => $order->id, 'amount' => $order->total]);
    $payment = app(SettleOnlinePayment::class)->handle($order, PaymentMethod::UzqrDynamic, 'uzqr', $transaction->pay_id, $order->total);

    expect(OrderPayment::query()->unreconciled()->pluck('id')->all())->toBe([$payment->id])
        ->and(UzqrTransaction::query()->receiptRequired()->count())->toBe(1);

    app(SetPaymentReconciled::class)->handle($payment, true, $this->admin);
    app(ConfirmUzqrFiscalReceipt::class)->handle($transaction, $this->admin);

    expect(OrderPayment::query()->unreconciled()->count())->toBe(0)
        ->and(UzqrTransaction::query()->receiptRequired()->count())->toBe(0)
        ->and(fn () => app(ConfirmUzqrFiscalReceipt::class)->handle($transaction, $this->admin))->toThrow(ValidationException::class);
});

it('refuses SettleOnlinePayment with another amount or method', function () {
    $order = Uzqr::order();

    expect(fn () => app(SettleOnlinePayment::class)->handle($order, PaymentMethod::UzqrDynamic, 'uzqr', 'X', $order->total - 1))->toThrow(LogicException::class)
        ->and(fn () => app(SettleOnlinePayment::class)->handle($order, PaymentMethod::Payme, 'payme', 'X', $order->total))->toThrow(LogicException::class);
});

it('rejects a second ledger row for the same pay_id at the database', function () {
    OrderPayment::factory()->create(['provider' => 'uzqr', 'provider_ref' => Uzqr::PAY_ID]);

    expect(fn () => OrderPayment::factory()->create(['provider' => 'uzqr', 'provider_ref' => Uzqr::PAY_ID]))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => UzqrTransaction::factory()->count(2)->create(['pay_id' => Uzqr::PAY_ID]))->toThrow(UniqueConstraintViolationException::class);
});

it('gives the resolve and receipt abilities to the Admin only', function (Role $role, bool $resolve, bool $cancel, bool $check) {
    $user = staffUser($role);
    $order = Uzqr::order();

    expect($user->can('resolveUzqr', $order))->toBe($resolve)
        ->and($user->can('cancelUzqr', $order))->toBe($cancel)
        ->and($user->can('checkUzqr', $order))->toBe($check);
})->with([
    'admin' => [Role::Admin, true, true, true],
    'sales manager' => [Role::SalesManager, false, true, true],
    'service engineer' => [Role::ServiceEngineer, false, false, false],
    'content manager' => [Role::ContentManager, false, false, false],
]);
