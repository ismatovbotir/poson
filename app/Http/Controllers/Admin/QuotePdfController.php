<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Quotes\ChangeQuoteStatus;
use App\Actions\Quotes\GenerateQuotePdf;
use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Download a quote as a PDF from the admin (QuotePolicy::view): the signed
 * PDF (stamp + director signature), the only place those images appear.
 *
 * Downloading a draft marks it sent when the user may change its status:
 * the PDF is what goes to the customer. This is a GET with a side effect;
 * it is accepted because it needs the authenticated staff session (the
 * "admin" middleware), only moves a draft forward (idempotent, never back)
 * and is logged in the quote history.
 */
class QuotePdfController extends Controller
{
    public function __invoke(Quote $quote, GenerateQuotePdf $generatePdf, ChangeQuoteStatus $changeStatus): Response
    {
        Gate::authorize('view', $quote);

        /** @var User $user */
        $user = auth()->user();

        if ($quote->status === QuoteStatus::Draft && $user->can('changeStatus', $quote)) {
            $changeStatus->markSent($quote, $user, 'pdf');
        }

        return $generatePdf->download($quote, signed: true);
    }
}
