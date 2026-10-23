<?php

namespace App\Http\Controllers\Site;

use App\Actions\Quotes\GenerateQuotePdf;
use App\Enums\ActivityType;
use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Quote;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * The customer's quote link /q/{ulid} (plan 06): no login, unguessable
 * ULID, noindex (meta + X-Robots-Tag), private no-store caching,
 * no-referrer, throttled (page 60/min, PDF 10/min), kept out of analytics
 * and robots.txt. Drafts and unknown links are 404. ?lang=uz|ru|en
 * switches the language (anything else: the quote's). Shows the quote's
 * own lines only, with Print and Download PDF, an "expired" banner after
 * valid_until, the director's name and the stamp place, but never the
 * stamp or signature images (user decision: those are only in the PDF a
 * manager downloads in admin).
 *
 * Opening the page writes "customer opened the quote" in the quote's
 * history at most once per Tashkent day (no IP or personal data).
 */
class PublicQuoteController extends Controller
{
    public const ROBOTS = 'noindex, nofollow, noarchive';

    public function show(Request $request, string $ulid, GenerateQuotePdf $generatePdf): SymfonyResponse
    {
        $quote = $this->find($ulid);
        $locale = $this->locale($request, $quote);

        $this->recordOpened($quote);

        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            $html = view('site.quote', [
                'quote' => $quote,
                'locale' => $locale,
                'pdf' => $generatePdf->data($quote, $locale),
            ])->render();
        } finally {
            app()->setLocale($previous);
        }

        return $this->private(new Response($html));
    }

    public function pdf(Request $request, string $ulid, GenerateQuotePdf $generatePdf): SymfonyResponse
    {
        $quote = $this->find($ulid);

        return $this->private($generatePdf->download($quote, $this->locale($request, $quote)));
    }

    private function find(string $ulid): Quote
    {
        $quote = Quote::query()->where('ulid', strtoupper($ulid))->first();

        abort_if($quote === null || $quote->status === QuoteStatus::Draft, 404);

        return $quote;
    }

    private function locale(Request $request, Quote $quote): string
    {
        $lang = $request->query('lang');

        return is_string($lang) && in_array($lang, config('app.supported_locales'), true) ? $lang : $quote->locale;
    }

    private function private(SymfonyResponse $response): SymfonyResponse
    {
        $response->headers->set('X-Robots-Tag', self::ROBOTS);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    /**
     * One "opened" entry per quote per Tashkent day. The page must work
     * even if this fails.
     */
    private function recordOpened(Quote $quote): void
    {
        try {
            $today = now(Lead::TIMEZONE);

            if (! Cache::add("quote-opened:{$quote->id}:{$today->toDateString()}", true, $today->copy()->endOfDay())) {
                return;
            }

            $alreadyToday = $quote->activities()
                ->where('type', ActivityType::Quote->value)
                ->where('meta->action', 'opened')
                ->where('created_at', '>=', $today->copy()->startOfDay()->utc())
                ->exists();

            if (! $alreadyToday) {
                $quote->activities()->create([
                    'type' => ActivityType::Quote,
                    'meta' => ['quote_id' => $quote->id, 'number' => $quote->number, 'action' => 'opened'],
                    'created_at' => now(),
                ]);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
