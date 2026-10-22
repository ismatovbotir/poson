<?php

use App\Http\Middleware\CaptureAttribution;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\SetAdminLocale;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrackVisit;
use App\Http\Middleware\TrustProxies;
use App\Support\Site\CanonicalHost;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies as BaseTrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('web')
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'locale' => SetLocale::class,
            'admin' => EnsureStaff::class,
            'admin.locale' => SetAdminLocale::class,
        ]);

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
        $middleware->web(append: [CaptureAttribution::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
