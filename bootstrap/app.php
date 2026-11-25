<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\AuthenticateMcpToken;
use App\Http\Middleware\BlockSearchIndexing;
use App\Http\Middleware\CaptureAttribution;
use App\Http\Middleware\ConfigureTmaSession;
use App\Http\Middleware\DenyFraming;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\EnsureTmaUser;
use App\Http\Middleware\LimitJsonBody;
use App\Http\Middleware\SetAdminLocale;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetTmaLocale;
use App\Http\Middleware\TmaHeaders;
use App\Http\Middleware\TrackVisit;
use App\Http\Middleware\TrustProxies;
use App\Http\Middleware\VerifyTelegramSecret;
use App\Support\Careers\CareersPostTooLarge;
use App\Support\Site\CanonicalHost;
use App\Support\Telegram\MiniApp\TmaErrorPage;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Middleware\TrustProxies as BaseTrustProxies;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Livewire\Livewire;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Plan 09 price API: registered by hand (no install:api, no Sanctum).
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('web')
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));

            // Plan 20: the Telegram Mini App, outside "web" (own session, headers and noindex).
            Route::middleware('tma')
                ->prefix('tma')
                ->name('tma.')
                ->group(base_path('routes/tma.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'locale' => SetLocale::class,
            'admin' => EnsureStaff::class,
            'admin.password-changed' => EnsurePasswordChanged::class,
            'admin.locale' => SetAdminLocale::class,
            'api.token' => AuthenticateApiToken::class,
            // Plan 13 MCP (routes/ai.php). Deliberately NOT in the priority list, so the
            // route keeps its order: throttle:mcp-ip → mcp.auth → EnsureMcpUser → throttle:mcp.
            'mcp.auth' => AuthenticateMcpToken::class,
            // Plan 20: the Telegram Mini App (routes/tma.php).
            'tma.locale' => SetTmaLocale::class,
            'tma.user' => EnsureTmaUser::class,
        ]);

        // Plan 20 (MA-5): the Mini App group has its own session cookie (path /tma, SameSite=None,
        // Partitioned) and headers (MA-3); no "web" middleware runs there. TmaHeaders is first so
        // every response, a 404 or 419 included, gets the CSP and noindex.
        $middleware->group('tma', [
            TmaHeaders::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            ConfigureTmaSession::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            PreventRequestForgery::class,
            SubstituteBindings::class,
        ]);
        $middleware->prependToPriorityList(before: StartSession::class, prepend: ConfigureTmaSession::class);
        // The signed-in user is checked before route bindings and the per-user throttles.
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: EnsureTmaUser::class);

        // Price API order (plan 09): body size → token → per-token throttle (the
        // throttle key needs the token; ThrottleRequests is otherwise sorted first).
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: AuthenticateApiToken::class);
        $middleware->prependToPriorityList(before: AuthenticateApiToken::class, prepend: LimitJsonBody::class);
        // Telegram webhook (plan 14 v2, condition 2): body size → secret → throttle.
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: VerifyTelegramSecret::class);

        // Plan 40 (YD-9): Metrika's ClientID cookie, read-only, Metrika JS sets it, never trusted
        // (only AdClickIds::capture() reads it, digits only).
        $middleware->encryptCookies(except: ['_ym_uid']);

        // Only the admin panel has authentication.
        $middleware->redirectGuestsTo(fn (Request $request): string => route('admin.login'));
        $middleware->redirectUsersTo(fn (Request $request): string => route('admin.dashboard'));

        // Only APP_URL's host (and its www twin) is answered; skipped in local and tests by Laravel.
        $middleware->trustHosts(at: fn (): array => CanonicalHost::trustedHosts(), subdomains: false);

        // Proxies come from config('app.trusted_proxies') (env TRUSTED_PROXIES); empty trusts none.
        $middleware->replace(BaseTrustProxies::class, TrustProxies::class);
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        // Traffic analytics (plan 04 4c): TrackVisit is global so 404s are seen and crawlers
        // get no session; CaptureAttribution runs inside the session (first-touch attribution).
        $middleware->append(TrackVisit::class);
        // Plan 15 C: X-Robots-Tag noindex, nofollow on every web response while the site is closed for indexing.
        // Plan 20 (MA-4): nobody frames the site, the admin or the customer links (DenyFraming).
        // Prepended, so 419 and route-binding 404 responses carry it too (security review L3).
        $middleware->web(prepend: [DenyFraming::class], append: [CaptureAttribution::class, BlockSearchIndexing::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Plan 20 (MA-11): the Mini App's signed initData is never flashed back.
        $exceptions->dontFlash(['init_data']);

        // Plan 20 (security review L8): Mini App HTML errors (404, 419, 429, 500…) use the CSP-safe
        // tma/error view (no inline style, no site layout, no locale cookie). JSON keeps the default.
        $exceptions->render(fn (Throwable $exception, Request $request) => TmaErrorPage::render($exception, $request));

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Plan 31 (CR-1): a CV above post_max_size on the careers form → back to the form with "file too large".
        $exceptions->render(fn (PostTooLargeException $exception, Request $request) => CareersPostTooLarge::redirect($request));

        // Plan 24 A1: an open admin tab polling after sign-out gets a plain refusal, not the login page's HTML.
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! Livewire::isLivewireRequest()) {
                return null;
            }

            // The update URL is shared with the public site, so look at the page the component lives on.
            $isAdminPage = fn (string $path): bool => str_starts_with(trim($path, '/').'/', 'admin/');
            $referer = (string) parse_url((string) $request->headers->get('referer'), PHP_URL_PATH);

            if ($isAdminPage($referer) || $isAdminPage(Livewire::originalPath())) {
                return response(__('admin.auth.session_ended'), 403);
            }
        });
    })->create();
