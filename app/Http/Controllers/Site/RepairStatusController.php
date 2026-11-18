<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Repair;
use App\Models\Setting;
use App\Support\CompanySettings;
use App\Support\Repairs\RepairPublicStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The repair status page behind the receipt QR, /r/{token} (plan 35 I3, P35-10
 * to P35-13). No login, no session or cookie, noindex, no-store, throttled.
 *
 * One lookup shape for every miss (wrong length, unknown, rotated, expired):
 * the same neutral 404 body and headers. The page gets only RepairPublicStatus.
 */
class RepairStatusController extends Controller
{
    public const TOKEN_LENGTH = 40;

    public function show(Request $request, string $token, CompanySettings $company): Response
    {
        $repair = $this->find($token);

        if ($repair === null) {
            return $this->render('site.repair-status-missing', $this->locale($request, null), 404, $company);
        }

        return $this->render('site.repair-status', $this->locale($request, $repair->locale), 200, $company, $repair, $token);
    }

    /**
     * The repair behind a live link, else null. No query for a malformed token.
     */
    private function find(string $token): ?Repair
    {
        if (strlen($token) !== self::TOKEN_LENGTH || ! ctype_alnum($token)) {
            return null;
        }

        $repair = Repair::query()->where('public_token', $token)->first([...RepairPublicStatus::COLUMNS, 'public_token']);

        // The DB collation may be case-insensitive: confirm the exact token.
        if (! $repair instanceof Repair || ! hash_equals((string) $repair->public_token, $token) || ! $repair->isStatusLinkLive()) {
            return null;
        }

        return $repair;
    }

    private function locale(Request $request, ?string $repairLocale): string
    {
        $supported = config('app.supported_locales');
        $lang = $request->query('lang');

        if (is_string($lang) && in_array($lang, $supported, true)) {
            return $lang;
        }

        return is_string($repairLocale) && in_array($repairLocale, $supported, true) ? $repairLocale : (string) config('app.locale');
    }

    private function render(string $view, string $locale, int $status, CompanySettings $company, ?Repair $repair = null, ?string $token = null): Response
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            $html = view($view, [
                'locale' => $locale,
                'status' => $repair instanceof Repair ? RepairPublicStatus::fromRepair($repair, $locale) : null,
                'company' => [
                    'name' => $company->name($locale),
                    'phone' => $company->phoneDisplay(),
                    'address' => $company->address($locale),
                    'hours' => $company->workingHours($locale),
                    'telegram' => $company->telegramUrl(),
                ],
                'logo' => Setting::originalDataUri(Setting::LOGO, ['image/png', 'image/jpeg']),
                'switchUrls' => $token === null ? [] : collect(config('app.supported_locales'))
                    ->mapWithKeys(fn (string $lang): array => [$lang => route('repairs.status', ['token' => $token, 'lang' => $lang], absolute: false)])
                    ->all(),
            ])->render();
        } finally {
            app()->setLocale($previous);
        }

        $response = new Response($html, $status);
        $response->headers->set('X-Robots-Tag', PublicOrderController::ROBOTS);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
