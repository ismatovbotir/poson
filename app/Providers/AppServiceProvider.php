<?php

namespace App\Providers;

use App\Enums\AiProvider;
use App\Enums\Role;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\SetAdminLocale;
use App\Http\Middleware\SetLocale;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Page;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\AiTextProvider;
use App\Services\Ai\ClaudeTextAssistant;
use App\Services\Ai\OpenRouterTextAssistant;
use App\Services\Ai\TextAssistant;
use App\Services\Ai\TextAssistants;
use App\Support\Analytics\DnsResolver;
use App\Support\Analytics\SystemDnsResolver;
use App\Support\CompanySettings;
use App\Support\Secrets;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped: reset between Octane requests and queued jobs, so the memoized
        // overrides never outlive a request.
        $this->app->scoped(CompanySettings::class);

        // Singleton: keeps the captured .env values and the applied version
        // for the whole process (queue workers included).
        $this->app->singleton(Secrets::class);

        // Crawler verification DNS (tests bind a fake resolver).
        $this->app->bind(DnsResolver::class, SystemDnsResolver::class);

        // Admin AI assist (plan 02, plan 10): texts on Claude through laravel/ai or
        // on OpenRouter, one binding per provider (tests bind fakes under the same keys).
        $this->app->bind(TextAssistants::binding(AiProvider::Anthropic), ClaudeTextAssistant::class);
        $this->app->bind(TextAssistants::binding(AiProvider::OpenRouter), OpenRouterTextAssistant::class);
        $this->app->bind(TextAssistant::class, fn (): TextAssistant => TextAssistants::for(AiTextProvider::current()));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Lazy loading, silently discarded attributes and missing attributes throw outside production.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Morph types are stored as short aliases, never class names (renaming a
        // class can't orphan rows). Enforced: every model that owns a morph row
        // must be listed, or getMorphClass() throws. Owners today: media
        // (medialibrary: brand, category, page, product, setting), roles/permissions
        // (spatie: user), faq_items (category, product, page) and the CRM activities
        // timeline (lead, customer, quote). Livewire 4 reads
        // this map directly, so models as component properties are unaffected.
        Relation::enforceMorphMap([
            'brand' => Brand::class,
            'category' => Category::class,
            'customer' => Customer::class,
            'lead' => Lead::class,
            // Plan 07 content. news_post / banner: models arrive in 7b / 7c.
            'news_post' => 'App\Models\NewsPost',
            'banner' => 'App\Models\Banner',
            'page' => Page::class,
            'product' => Product::class,
            'quote' => Quote::class,
            'setting' => Setting::class,
            'user' => User::class,
        ]);

        // Integration settings and secrets (Settings → Integrations) over config: .env > admin > default.
        Secrets::apply();

        // Long-running workers pick up integration changes before the next job.
        Queue::before(fn () => Secrets::reapplyIfStale());

        // No overlay while config:cache / optimize run (DB secrets must never reach the config cache file).
        Event::listen(fn (CommandStarting $event) => Secrets::suspendForCommand($event->command, starting: true));
        Event::listen(fn (CommandFinished $event) => Secrets::suspendForCommand($event->command, starting: false));

        // Customers' quote links (plan 06): separate per-IP budgets for the page and the PDF.
        RateLimiter::for('quote-page', fn (Request $request) => Limit::perMinute(60)->by('quote-page:'.$request->ip()));
        RateLimiter::for('quote-pdf', fn (Request $request) => Limit::perMinute(10)->by('quote-pdf:'.$request->ip()));

        // Price API (plan 09): 30 batches per minute per token (after AuthenticateApiToken).
        RateLimiter::for('price-api', fn (Request $request) => Limit::perMinute(30)
            ->by('price-api:'.($request->attributes->get(AuthenticateApiToken::ATTRIBUTE)?->id ?? $request->ip()))
            ->response(fn (Request $request, array $headers) => response()->json([
                'error' => 'rate_limited',
                'message' => 'Too many requests for this token. Retry later.',
            ], 429, $headers)));

        // Admins may do everything; other roles go through permissions and policies.
        Gate::before(fn (User $user): ?bool => $user->hasRole(Role::Admin) ? true : null);

        // Re-apply the page locale (site) and the staff check + UI locale (admin) on Livewire update requests.
        Livewire::addPersistentMiddleware([SetLocale::class, EnsureStaff::class, SetAdminLocale::class]);

        // The company logo / OG image URL appears once its queued conversion exists.
        Event::listen(function (ConversionHasBeenCompletedEvent $event): void {
            if ($event->media->model_type === (new Setting)->getMorphClass()) {
                CompanySettings::flush();
            }
        });
    }
}
