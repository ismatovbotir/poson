<?php

namespace App\Providers;

use App\Enums\Role;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\SetAdminLocale;
use App\Http\Middleware\SetLocale;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Analytics\DnsResolver;
use App\Support\Analytics\SystemDnsResolver;
use App\Support\CompanySettings;
use App\Support\Secrets;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
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
        // (medialibrary: brand, category, product, setting), roles/permissions
        // (spatie: user), faq_items (category, product) and the CRM activities
        // timeline (lead, customer). Livewire 4 reads
        // this map directly, so models as component properties are unaffected.
        Relation::enforceMorphMap([
            'brand' => Brand::class,
            'category' => Category::class,
            'customer' => Customer::class,
            'lead' => Lead::class,
            'product' => Product::class,
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
