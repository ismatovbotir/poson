<?php

use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Livewire\Admin\Banners\Form as BannerForm;
use App\Livewire\Admin\Brands\Form as BrandForm;
use App\Livewire\Admin\News\Form as NewsForm;
use App\Models\Brand;
use App\Models\SettingsAudit;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

/**
 * The in-admin AI assistant is removed (decision 2026-10-28): no screens,
 * routes, permissions, schedule entries or classes are left, while the
 * brand rule on content forms and the AI-crawler features stay.
 */
it('404s the removed AI screens for the admin', function (string $url) {
    $this->actingAs(staffUser())->get($url)->assertNotFound();
})->with([
    'usage' => ['/admin/ai/usage'],
    'prompts' => ['/admin/settings/ai-prompts'],
    'prompt' => ['/admin/settings/ai-prompts/product.description'],
]);

it('keeps the AI-crawler routes and drops the assistant routes', function () {
    expect(Route::has('admin.analytics.ai'))->toBeTrue()
        ->and(Route::has('robots'))->toBeTrue()
        ->and(Route::has('llms'))->toBeTrue()
        ->and(collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn (string $name): bool => str_starts_with($name, 'admin.ai.') || str_starts_with($name, 'admin.settings.ai-prompts')))->toBeEmpty();
});

it('stores audit areas from the enum only and reads unknown values as null', function () {
    expect(fn () => new SettingsAudit(['area' => 'ai_prompts']))->toThrow(TypeError::class)
        ->and((new SettingsAudit(['area' => SettingsArea::Seo]))->area)->toBe(SettingsArea::Seo)
        ->and((new SettingsAudit)->setRawAttributes(['area' => 'ai_prompts'])->area)->toBeNull();
});

it('opens the admin dashboard for every staff role', function (Role $role) {
    $this->actingAs(staffUser($role))->get(route('admin.dashboard'))->assertOk();
})->with([
    'admin' => [Role::Admin],
    'content manager' => [Role::ContentManager],
    'sales manager' => [Role::SalesManager],
    'service engineer' => [Role::ServiceEngineer],
]);

it('seeds no AI permissions but keeps analytics', function () {
    staffUser();

    expect(Permission::query()->where('name', 'like', 'ai%')->pluck('name')->all())->toBe([])
        ->and(Permission::query()->where('name', 'analytics.view')->exists())->toBeTrue();
});

it('schedules and registers no AI commands', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())->not->toContain('ai:')
        ->and(Artisan::all())->not->toHaveKey('ai:prune-requests');
});

it('has no AI assistant classes or config left', function () {
    foreach (['Ai', 'Actions/Ai', 'Services/Ai', 'Support/Ai', 'Livewire/Admin/Ai', 'Livewire/Admin/Settings/AiPrompts'] as $directory) {
        expect(is_dir(app_path($directory)))->toBeFalse();
    }

    $leftovers = collect(File::allFiles(app_path()))
        ->map(fn (SplFileInfo $file): string => $file->getFilename())
        ->filter(fn (string $name): bool => preg_match('/^(WithAi|AiAssist|AiRequest|AiPrompt|AiFeature|AiProvider|AiErrorCode|BrandGuard|RunAiRequest|PruneAiRequests)/', $name) === 1);

    expect($leftovers->all())->toBe([])
        ->and(array_map(basename(...), glob(config_path('ai*.php'))))->toBe(['ai-bots.php'])
        ->and(config()->has('ai'))->toBeFalse()
        ->and(class_exists('App\Models\AiRequest'))->toBeFalse();
});

/**
 * The field's first error is the BrandSafeText message (in the admin locale).
 */
function expectBrandSafeError(Testable $component, string $field): void
{
    $message = (string) $component->errors()->first($field);
    $tail = trim(Str::after(__('validation.brand_safe'), ':Attribute'));

    expect($tail)->not->toBe('')
        ->and($message)->toEndWith($tail);
}

describe('brand rule on content forms', function () {
    it('rejects a banned phrase on the brand form', function () {
        $component = Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(BrandForm::class, ['brand' => Brand::factory()->create()])
            ->set('form.meta_title.uz', 'Zebra chegirma bilan')
            ->call('save')
            ->assertHasErrors('form.meta_title.uz');

        expectBrandSafeError($component, 'form.meta_title.uz');
    });

    it('rejects a banned phrase on the banner form', function () {
        $component = Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(BannerForm::class)
            ->set('form.title.uz', 'Katta chegirma')
            ->call('save')
            ->assertHasErrors('form.title.uz');

        expectBrandSafeError($component, 'form.title.uz');
    });

    it('rejects a banned phrase on the news form', function () {
        $component = Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(NewsForm::class)
            ->set('form.title.uz', 'Yangi yil chegirma')
            ->call('save')
            ->assertHasErrors('form.title.uz');

        expectBrandSafeError($component, 'form.title.uz');
    });
});
