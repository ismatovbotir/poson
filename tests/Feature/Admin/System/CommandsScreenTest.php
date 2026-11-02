<?php

use App\Console\Commands\Fakes\FakeHiddenCommand;
use App\Console\Commands\Fakes\FakeSecretCommand;
use App\Enums\Role;
use App\Livewire\Admin\System\Commands;
use App\Support\Admin\CommandCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../../../Fixtures/FakeAppCommands.php';

beforeEach(function () {
    Cache::flush();
    Artisan::registerCommand(new FakeSecretCommand);
    Artisan::registerCommand(new FakeHiddenCommand);
});

it('lets only the Admin open the page', function () {
    $this->actingAs(staffUser(Role::Admin))->get(route('admin.commands'))->assertOk()->assertSee(__('commands.title'));
});

it('forbids every other role', function (Role $role) {
    $this->actingAs(staffUser($role))->get(route('admin.commands'))->assertForbidden();
    Livewire::actingAs(staffUser($role))->test(Commands::class)->assertForbidden();
})->with([Role::ContentManager, Role::SalesManager, Role::ServiceEngineer]);

it('re-checks the permission on updates', function () {
    $component = Livewire::actingAs(staffUser(Role::Admin))->test(Commands::class);

    $this->actingAs(staffUser(Role::SalesManager));

    $component->set('search', 'quotes')->assertForbidden();
});

it('shows the Commands menu item only with system.commands', function () {
    $this->actingAs(staffUser(Role::Admin))->get(route('admin.commands'))->assertSee(route('admin.commands'), false);
    $this->actingAs(staffUser(Role::SalesManager))->get(route('admin.dashboard'))->assertDontSee(route('admin.commands'), false);
});

it('creates the permission with an add-only idempotent migration', function () {
    $migration = require database_path('migrations/2026_11_03_100010_add_system_commands_permission.php');
    $migration->up();
    $migration->up();

    expect(Permission::query()->where('name', 'system.commands')->count())->toBe(1)
        ->and(staffUser(Role::Admin)->can('system.commands'))->toBeTrue()
        ->and(staffUser(Role::SalesManager)->can('system.commands'))->toBeFalse();
});

it('lists only the app commands by default and framework ones on toggle', function () {
    Livewire::actingAs(staffUser())->test(Commands::class)
        ->assertSee('quotes:expire')
        ->assertSee('fake:secret')
        ->assertDontSee('migrate:status')
        ->set('showFramework', true)
        ->assertSee('migrate:status');
});

it('searches name, description and help, capped at 100 chars', function () {
    Livewire::actingAs(staffUser())->test(Commands::class)
        ->set('search', 'QUOTES:EXP')->assertSee('quotes:expire')->assertDontSee('carts:prune')
        ->set('search', 'rotating APP_KEY')->assertSee('secrets:reencrypt')->assertDontSee('quotes:expire')
        ->set('search', 'zzz-no-such')->assertSee(__('commands.empty_title'))
        ->set('search', str_repeat('a', 300))->assertSet('search', str_repeat('a', 100));
});

it('types the framework toggle as bool', function () {
    $thrown = false;

    try {
        Livewire::actingAs(staffUser())->test(Commands::class)->set('showFramework', ['x']);
    } catch (Throwable) {
        $thrown = true;
    }

    expect($thrown)->toBeTrue();
});

it('never shows defaults of sensitive-looking options and skips hidden commands', function () {
    $items = collect(CommandCatalog::all())->keyBy('name');
    $options = collect($items['fake:secret']['options'])->keyBy('name');

    expect($items->has('fake:hidden'))->toBeFalse()
        ->and($options['api-token']['default'])->toBeNull()
        ->and($options['apikey']['default'])->toBeNull()
        ->and($options['authtoken']['default'])->toBeNull()
        ->and($options['mode']['default'])->toBe('fast');

    Livewire::actingAs(staffUser())->test(Commands::class)->assertDontSee('sk_live_FAKE_TOKEN_123')->assertDontSee('fake:hidden');
});

it('escapes markup, strips console tags and uses no raw output', function () {
    $html = Livewire::actingAs(staffUser())->test(Commands::class)->set('search', 'fake:secret')->html();

    expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->not->toContain('<script>alert(1)')
        ->not->toContain('<comment>')
        ->not->toContain('&lt;comment&gt;')
        ->not->toContain('fg=red')
        ->toContain('Fake help text and red')
        ->toContain('whitespace-pre-wrap');

    expect(file_get_contents(resource_path('views/livewire/admin/system/commands.blade.php')))->not->toContain('{!!');
});

it('caches plain arrays so a request does not call Artisan::all again', function () {
    $items = CommandCatalog::all();

    expect(Cache::get(CommandCatalog::cacheKey()))->toBe($items)
        ->and(json_encode($items))->toBeString();

    Artisan::registerCommand(new class extends Command
    {
        protected $signature = 'fake:late';
    });

    expect(collect(CommandCatalog::all())->pluck('name'))->not->toContain('fake:late');
});

it('shows the cron expression for scheduled app commands only', function () {
    $items = collect(CommandCatalog::all())->keyBy('name');

    expect($items['quotes:expire']['schedule'])->not->toBe([])
        ->and($items['quotes:expire']['schedule'][0]['expression'])->toMatch('/^\d+ \d+ \* \* \*$/')
        ->and($items['uzqr:sync']['schedule'][0])->toBe(['expression' => '* * * * *', 'human' => 'every minute'])
        ->and($items['secrets:reencrypt']['schedule'])->toBe([])
        ->and($items['model:prune']['schedule'])->toBe([]);
});

it('gives every app command a description and a help text', function () {
    foreach (collect(CommandCatalog::all())->where('is_app', true)->reject(fn ($c) => str_starts_with($c['name'], 'fake:') || $c['name'] === 'inspire') as $command) {
        expect($command['description'])->not->toBe('', $command['name'])
            ->and($command['help'])->not->toBe('', $command['name']);
    }
});
