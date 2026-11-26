<?php

namespace App\Http\Controllers\Tma;

use App\Actions\Orders\GenerateOrderInvoicePdf;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\TelegramUser;
use App\Support\CompanySettings;
use App\Support\Telegram\MiniApp\TmaLinks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * "My orders" (plan 20 §6.2, M4; MA-10, MA-17, MA-22): only real orders the
 * signed-in user placed in the Mini App (orders.telegram_user_id), the last
 * LIMIT. An order is named by its numeric id; any other id (someone else's,
 * a TEST order, a site order) is the same 404.
 *
 * The page shows number, status, lines, delivery and the Retail totals.
 * A bank transfer shows the seller's requisites only once the order is
 * confirmed (or later, not cancelled), and the invoice PDF opens outside
 * Telegram (openLink to /o/{ulid}/invoice.pdf) only while /o would serve it:
 * the public link is valid. The ULID appears nowhere else. The /o page and
 * its UzQR component are never embedded.
 */
class OrderController extends TmaController
{
    public const LIMIT = 20;

    public function index(Request $request): Response
    {
        $orders = $this->own($this->user($request))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            // Plan 45: the item count and the first item's name (one query for all orders).
            ->with(['items' => fn (HasMany $items) => $items->select(['id', 'order_id', 'name'])->orderBy('id')])
            ->get(['id', 'number', 'status', 'total', 'created_at', 'payment_method']);

        return $this->page($request, 'tma.orders.index', [
            'title' => __('tma.orders.title'),
            'back' => route('tma.home'),
            'orders' => $orders,
        ]);
    }

    public function show(Request $request, string $order, CompanySettings $company): Response
    {
        $model = $this->own($this->user($request))->whereKey((int) $order)->with('items')->first();

        abort_unless($model instanceof Order, 404);

        $bankDetails = static::showsBankDetails($model);

        return $this->page($request, 'tma.orders.show', [
            'title' => __('tma.orders.number', ['number' => $model->number]),
            'back' => route('tma.orders'),
            'order' => $model,
            'bank' => $bankDetails ? [
                'legal' => $company->legal(app()->getLocale()),
                'company' => ['name' => $company->name(app()->getLocale())],
                'amount' => $model->total,
                'purpose' => __('orders.public.purpose', ['number' => $model->number]),
            ] : null,
            'invoiceUrl' => $bankDetails && $model->isPublicLinkValid() && GenerateOrderInvoicePdf::hasInvoice($model)
                ? TmaLinks::own('orders.public.invoice', ['ulid' => $model->ulid, 'lang' => app()->getLocale()])
                : null,
            'placed' => session('tma_status') === 'placed',
        ]);
    }

    /**
     * Bank requisites only for a bank transfer confirmed by staff and not cancelled.
     */
    public static function showsBankDetails(Order $order): bool
    {
        return $order->payment_method === PaymentMethod::BankTransfer
            && in_array($order->status, [OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::Shipped, OrderStatus::Completed], true);
    }

    /**
     * @return Builder<Order>
     */
    protected function own(TelegramUser $user): Builder
    {
        return Order::query()->real()->where('orders.telegram_user_id', $user->id);
    }
}
