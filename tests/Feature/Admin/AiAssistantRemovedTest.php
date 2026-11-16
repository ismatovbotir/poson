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
 * Guard against UNAPPROVED AI surfaces. The in-admin AI assistant stays removed
 * (decision 2026-10-28): no assistant screens, routes, prompt settings, usage
 * pages, admin API-key fields or leftover classes. Since 2026-11-14 three AI
 * surfaces are approved and allowed here, and nothing else:
 *  - Plan 29 AI-fill (prompt building and a pasted answer, no API call),
 *  - Plan 30 chat bot (App\Services\Ai, key only in .env via config/services.php),
 *  - Plan 32 product wizard (ai.generate permission, ai:prune-drafts schedule,
 *    AiPromptRules, the RunAiDraftStage job).
 * The brand rule on content forms and the AI-crawler features stay too.
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

it('keeps AI keys in config/services.php and .env only, with no admin key field', function () {
    $configKeys = collect(File::allFiles(config_path()))
        ->filter(fn (SplFileInfo $file): bool => preg_match('/anthropic|photoroom|openai/i', File::get($file->getPathname())) === 1 && preg_match('/env\(\s*[\'"][A-Z_]*(ANTHROPIC|PHOTOROOM|OPENAI)[A-Z_]*[\'"]/', File::get($file->getPathname())) === 1)
        ->map(fn (SplFileInfo $file): string => $file->getFilename())
        ->values()
        ->all();

    expect($configKeys)->toBe(['services.php'])
        ->and(config('services.anthropic.key'))->toBeEmpty();

    // No integration registry entry, settings area or admin view takes an AI key.
    $secretKeys = collect(File::allFiles(app_path('Support/Integrations')))->map(fn (SplFileInfo $file): string => strtolower(File::get($file->getPathname())))->implode("\n");
    expect($secretKeys)->not->toContain('anthropic')->not->toContain('openai')->not->toContain('photoroom');

    foreach (File::allFiles(resource_path('views/livewire/admin')) as $view) {
        $html = strtolower(File::get($view->getPathname()));

        foreach (['anthropic_api_key', 'anthropic.key', 'openai_api_key', 'ai_api_key', 'anthropic-key'] as $needle) {
            expect($html)->not->toContain($needle, "{$view->getFilename()} contains {$needle}");
        }
    }
});

it('reads env() only in config files', function () {
    $offenders = collect([...File::allFiles(app_path()), ...File::allFiles(base_path('routes')), ...File::allFiles(base_path('database'))])
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'php')
        ->filter(fn (SplFileInfo $file): bool => preg_match('/(?<![\w>:$])env\(\s*[\'"]/', File::get($file->getPathname())) === 1)
        ->map(fn (SplFileInfo $file): string => str_replace(base_path().'/', '', $file->getPathname()))
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

it('opens the admin dashboard for every staff role', function (Role $role) {
    $this->actingAs(staffUser($role))->get(route('admin.dashboard'))->assertOk();
})->with([
    'admin' => [Role::Admin],
    'content manager' => [Role::ContentManager],
    'sales manager' => [Role::SalesManager],
    'service engineer' => [Role::ServiceEngineer],
]);

it('seeds only the approved ai.generate permission and keeps analytics', function () {
    staffUser();

    expect(Permission::query()->where('name', 'like', 'ai%')->pluck('name')->all())->toBe(['ai.generate'])
        ->and(Permission::query()->where('name', 'analytics.view')->exists())->toBeTrue();
});

it('schedules and registers only the approved ai:prune-drafts command', function () {
    Artisan::call('schedule:list');

    preg_match_all('/artisan[\'"]?\s+(ai:[\w:-]+)/', Artisan::output(), $scheduled);

    expect(array_unique($scheduled[1]))->toBe(['ai:prune-drafts'])
        ->and(array_values(array_filter(array_keys(Artisan::all()), fn (string $name): bool => str_starts_with($name, 'ai:'))))->toBe(['ai:prune-drafts'])
        ->and(Artisan::all())->not->toHaveKey('ai:prune-requests');
});

it('has no AI assistant classes or config left', function () {
    // Plan 30 (owner decision 2026-11-14): App\Services\Ai is the chat bot's model adapter, the only AI in the app.
    foreach (['Ai', 'Actions/Ai', 'Support/Ai', 'Livewire/Admin/Ai', 'Livewire/Admin/Settings/AiPrompts'] as $directory) {
        expect(is_dir(app_path($directory)))->toBeFalse();
    }

    // The lookaheads let the Plan 29 files through (WithAiFill trait, AiPromptBuilder, AiPromptRules):
    // they only build prompt text and parse a pasted answer. Plan 30's chat bot files
    // (App\Services\Ai, App\Support\Chat\AiPrompt) are the only other exceptions.
    $leftovers = collect(File::allFiles(app_path()))
        ->reject(fn (SplFileInfo $file): bool => in_array($file->getPathname(), [
            ...array_map(fn (string $name): string => app_path('Services/Ai/'.$name.'.php'), ['ChatModel', 'AiRequest', 'AiResult', 'AiFailed', 'AiUnavailable', 'AnthropicChatModel']),
            app_path('Support/Chat/AiPrompt.php'),
        ], true))
        ->map(fn (SplFileInfo $file): string => $file->getFilename())
        ->filter(fn (string $name): bool => preg_match('/^(WithAi(?!Fill\.php)|AiAssist|AiRequest|AiPrompt(?!Builder\.php|Rules\.php)|AiFeature|AiProvider|AiErrorCode|BrandGuard|RunAiRequest|PruneAiRequests)/', $name) === 1);

    expect($leftovers->all())->toBe([])
        ->and(array_map(basename(...), glob(app_path('Services/Ai/*'))))->toEqualCanonicalizing(['AiFailed.php', 'AiRequest.php', 'AiResult.php', 'AiUnavailable.php', 'AnthropicChatModel.php', 'ChatModel.php'])
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

it('keeps the AI-fill support code free of HTTP calls and AI hosts', function () {
    $files = [...File::allFiles(app_path('Support/Content/AiFill')), new SplFileInfo(app_path('Livewire/Admin/Concerns/WithAiFill.php'))];

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $code = strtolower(File::get($file->getPathname()));

        foreach (['http::', 'curl', 'guzzle', 'openai', 'anthropic', 'googleapis', 'file_get_contents('] as $needle) {
            expect($code)->not->toContain($needle, "{$file->getFilename()} contains {$needle}");
        }
    }
});
