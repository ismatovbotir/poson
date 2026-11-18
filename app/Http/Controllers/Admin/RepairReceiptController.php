<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Repairs\EnsureRepairStatusToken;
use App\Actions\Repairs\GenerateRepairReceiptPdf;
use App\Actions\Repairs\LogRepairReceiptPrinted;
use App\Exceptions\QrUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Repair;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Print the repair intake receipt (plan 35, P35-6): RepairPolicy::printReceipt
 * first (before any token write), ?lang= checked against the supported
 * locales (else the repair's), the status token issued for older rows, the
 * print logged, the PDF streamed inline and never cached.
 */
class RepairReceiptController extends Controller
{
    public function __invoke(
        Request $request,
        Repair $repair,
        GenerateRepairReceiptPdf $generatePdf,
        EnsureRepairStatusToken $ensureToken,
        LogRepairReceiptPrinted $logPrinted,
    ): Response {
        Gate::authorize('printReceipt', $repair);

        $lang = $request->query('lang');
        $locale = is_string($lang) && in_array($lang, config('app.supported_locales'), true) ? $lang : (string) $repair->locale;

        $ensureToken->handle($repair);

        try {
            $response = $generatePdf->stream($repair, $locale);
        } catch (QrUnavailableException $e) {
            report($e);

            return response()->view('errors.repair-receipt', ['message' => __('repairs.receipt.no_gd')], 500);
        }

        /** @var User $user */
        $user = $request->user();
        $logPrinted->handle($repair, $user, $locale);

        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
