<?php

namespace App\Providers;

use App\Http\Middleware\SetLocale;
use App\Support\CompanySettings;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

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
        // Re-apply the page locale on Livewire update requests.
        Livewire::addPersistentMiddleware([SetLocale::class]);
    }
}
