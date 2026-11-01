<?php

use App\Actions\Settings\UpdateCompanySettings;
use App\Actions\Settings\UpdateSearchIndexing;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Livewire\Admin\Settings\Indexing;
use App\Models\SettingsAudit;
use App\Support\Admin\Menu;
use App\Support\CompanySettings;
use App\Support\Site\LlmsTxt;
use App\Support\Site\Sitemap;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

it('shows the switch, closed by default, to an Admin', function () {
    $this->actingAs(staffUser())
        ->get(route('admin.settings.indexing'))
        ->assertOk()
        ->assertSee(__('admin.settings.indexing.help'))
        ->assertSee('data-indexing-state="closed"', false);
});

it('forbids the screen and a direct save to every other role', function (Role $role) {
    $user = staffUser($role);

    $this->actingAs($user)->get(route('admin.settings.indexing'))->assertForbidden();
    Livewire::actingAs($user)->test(Indexing::class)->assertForbidden();

    expect(fn () => app(UpdateSearchIndexing::class)->handle(true, $user, '127.0.0.1'))->toThrow(AuthorizationException::class)
        ->and(app(CompanySettings::class)->isIndexingOpen())->toBeFalse();
})->with([
    'content manager' => [Role::ContentManager],
    'sales manager' => [Role::SalesManager],
    'service engineer' => [Role::ServiceEngineer],
]);

it('sends an Admin without a recent password confirmation to confirm it, changing nothing', function () {
    Livewire::actingAs(staffUser())->test(Indexing::class)
        ->set('open', true)
        ->call('save')
        ->assertRedirect(route('admin.confirm-password'));

    expect(app(CompanySettings::class)->isIndexingOpen())->toBeFalse()
        ->and(SettingsAudit::query()->count())->toBe(0);
});

it('opens and closes the site with an audit row each time and flushes the crawler caches', function () {
    $admin = staffUser();
    confirmAdminPassword();
    Cache::forever(Sitemap::CACHE_KEY, 'stale');
    Cache::forever(LlmsTxt::CACHE_KEY, 'stale');

    Livewire::actingAs($admin)->test(Indexing::class)
        ->set('open', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('open', true);

    expect(app(CompanySettings::class)->isIndexingOpen())->toBeTrue()
        ->and(Cache::has(Sitemap::CACHE_KEY))->toBeFalse()
        ->and(Cache::has(LlmsTxt::CACHE_KEY))->toBeFalse();

    $this->get('/sitemap.xml')->assertOk();

    Livewire::actingAs($admin)->test(Indexing::class)
        ->set('open', false)
        ->call('save')
        ->assertSet('open', false);

    $this->get('/sitemap.xml')->assertNotFound();

    $audits = SettingsAudit::query()->orderBy('id')->get();

    expect($audits)->toHaveCount(2)
        ->and($audits->pluck('action')->all())->toBe([AuditAction::Opened, AuditAction::Closed])
        ->and($audits->every(fn (SettingsAudit $audit): bool => $audit->area === SettingsArea::Indexing
            && $audit->key === CompanySettings::INDEXING_KEY
            && $audit->user_id === $admin->id
            && $audit->ip !== null))->toBeTrue();
});

it('writes no audit row when the state does not change', function () {
    confirmAdminPassword();

    Livewire::actingAs(staffUser())->test(Indexing::class)
        ->call('save')
        ->assertDispatched('toast');

    expect(SettingsAudit::query()->count())->toBe(0);
});

it('refuses the indexing switch through the generic company settings action and stays closed', function () {
    expect(fn () => app(UpdateCompanySettings::class)->handle(SettingsArea::Seo, ['search_indexing' => true], staffUser(), '127.0.0.1'))
        ->toThrow(InvalidArgumentException::class)
        ->and(app(CompanySettings::class)->isIndexingOpen())->toBeFalse();
});

it('hides the indexing menu item from a user who lacks settings.company', function () {
    $user = staffUser(Role::ContentManager);
    $user->givePermissionTo('settings.integrations');

    $items = collect(Menu::for($user))->flatMap(fn ($group) => $group->items)->pluck('key');

    expect($items)->not->toContain('indexing');
});
