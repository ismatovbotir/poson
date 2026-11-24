<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Analytics\ExportOfflineConversions;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Offline-conversion CSV download (plan 40 §3, YD-10): POST with CSRF from
 * Admin → Analytics → Campaigns, in the admin group, can:analytics.export,
 * throttle 10/min. 403 while the ad click id switch is off; invalid input
 * → 422 (plain text, the first message). Attachment with a fixed file name,
 * no-store, nosniff. ExportOfflineConversions validates, audits and logs.
 */
class OfflineConversionsCsvController extends Controller
{
    public function __invoke(Request $request, ExportOfflineConversions $export): Response
    {
        try {
            $result = $export->handle([
                'from' => $request->input('from'),
                'to' => $request->input('to'),
                'id_type' => $request->input('id_type'),
                'goals' => $request->input('goals'),
            ], $request->user(), $request->ip());
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            return response((string) collect($exception->errors())->flatten()->first(), 422, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return response($result['csv'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $result['filename']),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
