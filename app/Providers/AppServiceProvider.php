<?php

namespace App\Providers;

use App\Enums\Role;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\SetAdminLocale;
use App\Http\Middleware\SetLocale;
use App\Models\Setting;
use App\Models\User;
use App\Support\CompanySettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Lazy loading, silently discarded attributes and missing attributes throw outside production.
        Model::shouldBeStrict(! $this->app->isProduction());

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
