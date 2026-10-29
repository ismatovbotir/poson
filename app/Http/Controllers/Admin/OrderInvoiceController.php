<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Orders\GenerateOrderInvoicePdf;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Download an order's invoice in admin (plan 11 v2 §1.11, security
 * condition 8): OrderPolicy::viewPayments (Admin, sales manager; never the
 * service engineer, condition 30), bank-transfer and static-UzQR orders
 * only. ?signed=1 adds the stamp and the director's signature, and only
 * once the order is confirmed (not "new"); otherwise the PDF is unsigned,
 * like the customer's copy.
 */
class OrderInvoiceController extends Controller
{
    public function __invoke(Request $request, Order $order, GenerateOrderInvoicePdf $generatePdf): Response
    {
        Gate::authorize('viewPayments', $order);

        abort_unless(GenerateOrderInvoicePdf::hasInvoice($order), 404);

        $signed = $request->boolean('signed') && $order->status !== OrderStatus::New;

        $response = $generatePdf->download($order, signed: $signed);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
