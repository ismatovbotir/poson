<?php

use App\Actions\Settings\SetSecret;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Livewire\Admin\Settings\Integrations;
use App\Models\IntegrationSecret;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\Admin\Menu;
use App\Support\Admin\MenuGroup;
use App\Support\Admin\MenuItem;
use App\Support\Secrets;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

const SCREEN_SECRET = 'fake-payme-SCREENSECRET-000000000000wxyz';

const SCREEN_BOT_TOKEN = '123456789:AAScreenTokenScreenTokenScreenTok4321';

function integrationsScreen(?User $user = null): Testable
{
    return Livewire::actingAs($user ?? staffUser())->test(Integrations::class);
}

/**
 * Type a secret and press its Save button: one round trip, like the browser
 * (wire:model is deferred, so the value travels with the action call).
 */
function typeAndReplaceSecret(Testable $component, string $key, string $value): Testable
{
    return $component->update(
        calls: [['method' => 'replaceSecret', 'params' => [$key], 'path' => '']],
        updates: ['secretInputs.'.$key => $value],
    );
}

/**
 * Everything the browser receives for a component: HTML, snapshot and effects.
 */
function browserPayload(Testable $component): string
{
    return $component->html().json_encode($component->snapshot).json_encode($component->effects);
}

$otherRoles = [
    'content manager' => [Role::ContentManager],
    'sales manager' => [Role::SalesManager],
    'service engineer' => [Role::ServiceEngineer],
];

beforeEach(fn () => freshSecrets());

describe('access', function () use ($otherRoles) {
    it('opens for the admin with every section', function () {
        $html = $this->actingAs(staffUser())
            ->get(route('admin.settings.integrations'))
            ->assertOk()
            ->assertSeeLivewire(Integrations::class)
            ->getContent();

        foreach (['payme', 'click', 'uzqr', 'monitoring', 'indexnow'] as $group) {
            expect($html)->toContain('data-section="'.$group.'"');
        }

        // Telegram has its own page: Admin/Telegram/SettingsScreenTest.
        expect($html)->not->toContain('data-section="telegram"');

        // The AI assistant and remove.bg are gone (decision 2026-10-28).
        expect($html)->not->toContain('data-section="ai"')
            ->not->toContain('data-section="removebg"')
            ->not->toMatch('/data-(secret|setting)="(anthropic|openai|openrouter|removebg|ai)\./');
    });

    it('forbids every other staff role', function (Role $role) {
        $this->actingAs(staffUser($role))
            ->get(route('admin.settings.integrations'))
            ->assertForbidden();
    })->with($otherRoles);

    it('forbids direct method calls by another role', function (Role $role, string $method, array $params) {
        confirmAdminPassword();
        $component = integrationsScreen()->set('secretInputs.payme.key', SCREEN_SECRET);

        $this->actingAs(staffUser($role));

        $component->call($method, ...$params)->assertForbidden();

        expect(IntegrationSecret::query()->count())->toBe(0)
            ->and(Setting::query()->count())->toBe(0)
            ->and(SettingsAudit::query()->count())->toBe(0);
    })->with($otherRoles)->with([
        'save' => ['save', ['payme']],
        'replace' => ['replaceSecret', ['payme.key']],
        'clear' => ['clearSecret', ['payme.key']],
        'generate' => ['generateIndexNowKey', []],
        'test' => ['test', ['uzqr']],
    ]);

    it('shows the menu item by the settings.integrations permission', function () {
        $item = collect(Menu::definition())
            ->flatMap(fn (MenuGroup $group): array => $group->items)
            ->first(fn (MenuItem $item): bool => $item->key === 'integrations');

        expect($item->permission)->toBe('settings.integrations')
            ->and($item->roles)->toBeNull()
            ->and($item->isAvailable())->toBeTrue()
            ->and($item->isVisibleTo(staffUser()))->toBeTrue()
            ->and($item->isVisibleTo(staffUser(Role::ContentManager)))->toBeFalse();
    });
});

describe('password confirmation', function () {
    it('asks for the password before any write and returns here afterwards', function (string $method, array $params) {
        integrationsScreen()
            ->set('secretInputs.payme.key', SCREEN_SECRET)
            ->set('form.values.click.service_id', '12345')
            ->call($method, ...$params)
            ->assertRedirect(route('admin.confirm-password'));

        expect(session('url.intended'))->toBe(route('admin.settings.integrations'))
            ->and(IntegrationSecret::query()->count())->toBe(0)
            ->and(Setting::query()->count())->toBe(0);
    })->with([
        'save' => ['save', ['payme']],
        'replace' => ['replaceSecret', ['payme.key']],
        'clear' => ['clearSecret', ['payme.key']],
        'generate' => ['generateIndexNowKey', []],
        'test' => ['test', ['uzqr']],
    ]);

    it('shows the confirmation banner and disables the forms until confirmed', function () {
        integrationsScreen()
            ->assertSeeHtml('data-password-required')
            ->assertSeeHtml('<fieldset class="mt-5 max-w-4xl space-y-4 pb-10 lg:space-y-6" disabled');

        confirmAdminPassword();

        integrationsScreen()
            ->assertSeeHtml('data-password-confirmed')
            ->assertDontSeeHtml('data-password-required');
    });

    it('accepts a confirmation from the last 15 minutes and expires after that', function () {
        confirmAdminPassword(14 * 60);

        integrationsScreen()
            ->set('form.values.click.service_id', '12345')
            ->call('save', 'click')
            ->assertNoRedirect()
            ->assertHasNoErrors();

        expect(config('services.click.service_id'))->toBe('12345');

        $this->travel(2)->minutes();

        integrationsScreen()
            ->set('form.values.click.service_id', '67890')
            ->call('save', 'click')
            ->assertRedirect(route('admin.confirm-password'));

        expect(config('services.click.service_id'))->toBe('12345');
    });
});

describe('secrets', function () {
    it('saves a secret and never sends it back to the browser, logs or audit', function () {
        confirmAdminPassword();
        Exceptions::fake();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.json_encode($event->context);
        });

        $component = typeAndReplaceSecret(integrationsScreen(), 'payme.key', SCREEN_SECRET)
            ->assertHasNoErrors()
            ->assertSet('secretInputs.payme.key', '')
            ->assertDispatched('toast');

        expect(Secrets::get('payme.key'))->toBe(SCREEN_SECRET)
            ->and(browserPayload($component))->not->toContain('SCREENSECRET')
            ->and($component->html())->toContain('••••wxyz')
            ->and(json_encode(SettingsAudit::query()->get()->toArray()))->not->toContain('SCREENSECRET')
            ->and(implode("\n", $logged))->not->toContain('SCREENSECRET');

        // A fresh page load shows the status only.
        $page = $this->actingAs(staffUser())->get(route('admin.settings.integrations'))->assertOk()->getContent();

        expect($page)->not->toContain('SCREENSECRET')->toContain('••••wxyz');
        Exceptions::assertNothingReported();
    });

    it('never renders a saved secret of any field', function () {
        $values = [
            'telegram.bot_token' => SCREEN_BOT_TOKEN,
            'telegram.webhook_secret' => str_repeat('DDDDFOUR', 6),
            'payme.key' => 'fake-EEEEFIVE-payme-key',
            'payme.test_key' => 'fake-FFFFSIX-payme-test',
            'click.secret_key' => 'fake-GGGGSEVEN-click',
        ];

        foreach ($values as $key => $value) {
            IntegrationSecret::factory()->withValue($key, $value)->create();
        }
        Secrets::flush();
        confirmAdminPassword();

        $payload = browserPayload(integrationsScreen());

        foreach (['AAScreenToken', 'DDDDFOUR', 'EEEEFIVE', 'FFFFSIX', 'GGGGSEVEN'] as $marker) {
            expect($payload)->not->toContain($marker);
        }
    });

    it('never returns a typed secret, whatever the request', function () {
        confirmAdminPassword();

        $component = integrationsScreen()
            ->set('secretInputs.payme.key', SCREEN_SECRET)
            ->assertSet('secretInputs.payme.key', '');

        expect(browserPayload($component))->not->toContain('SCREENSECRET');

        $component->set('secretInputs.click.secret_key', 'fake-click-TYPEDONLY-000000000000000')
            ->set('form.values.click.service_id', '12345');

        expect(browserPayload($component))->not->toContain('TYPEDONLY')->not->toContain('SCREENSECRET');
    });

    it('renders write-only inputs that browsers must not autofill', function () {
        integrationsScreen()->assertSeeHtml('autocomplete="new-password"')->assertDontSeeHtml('autocomplete="current-password"');
    });

    it('resets the input and keeps the old value on a validation error', function () {
        confirmAdminPassword();

        $component = typeAndReplaceSecret(integrationsScreen(), 'payme.key', 'broken TOKENPART')
            ->assertHasErrors('secretInputs.payme.key')
            ->assertSet('secretInputs.payme.key', '');

        expect(browserPayload($component))->not->toContain('TOKENPART')
            ->and(IntegrationSecret::query()->count())->toBe(0);
    });

    it('shows the status of each secret', function () {
        IntegrationSecret::factory()->withValue('payme.test_key', 'fake-payme-test-0000000000000000000abcd')->create();
        freshSecrets(['services.payme.key' => 'fake-payme-env-0000000000000000000000']);

        integrationsScreen()
            ->assertSeeHtml('data-secret="payme.key"')
            ->assertSeeInOrder(['data-secret="payme.key"', 'data-status="env"', 'PAYME_KEY'], false)
            ->assertSeeInOrder(['data-secret="payme.test_key"', 'data-status="configured"', '••••abcd'], false)
            ->assertSeeInOrder(['data-secret="click.secret_key"', 'data-status="none"'], false)
            ->assertDontSee('fake-payme-env-');
    });

    it('refuses to replace a secret locked by .env', function () {
        freshSecrets(['services.payme.key' => 'fake-payme-env-0000000000000000000000']);
        confirmAdminPassword();

        typeAndReplaceSecret(integrationsScreen(), 'payme.key', SCREEN_SECRET)
            ->assertDispatched('toast', type: 'error');

        expect(IntegrationSecret::query()->count())->toBe(0);
    });

    it('clears a secret', function () {
        IntegrationSecret::factory()->withValue('click.secret_key', 'fake-click-secret-0000')->create();
        Secrets::flush();
        confirmAdminPassword();

        integrationsScreen()->call('clearSecret', 'click.secret_key')->assertDispatched('toast', type: 'success');

        expect(Secrets::isConfigured('click.secret_key'))->toBeFalse()
            ->and(SettingsAudit::query()->sole()->action)->toBe(AuditAction::Cleared);
    });

    it('404s for a key that is not a secret', function () {
        confirmAdminPassword();

        integrationsScreen()->call('replaceSecret', 'payme.mode')->assertNotFound();
    });
});

describe('settings', function () {
    it('saves a section and refills the form', function () {
        confirmAdminPassword();

        integrationsScreen()
            ->set('form.values.click.service_id', '12345')
            ->set('form.values.click.merchant_id', '67890')
            ->call('save', 'click')
            ->assertHasNoErrors()
            ->assertSet('form.values.click.service_id', '12345')
            ->assertDispatched('toast', type: 'success');

        expect(config('services.click.service_id'))->toBe('12345')
            ->and(config('services.click.merchant_id'))->toBe('67890')
            ->and(SettingsAudit::query()->where('area', SettingsArea::Integrations)->pluck('key')->sort()->values()->all())->toBe(['click.merchant_id', 'click.service_id']);
    });

    it('shows validation errors under the field', function () {
        confirmAdminPassword();

        integrationsScreen()
            ->set('form.values.click.service_id', 'not digits')
            ->call('save', 'click')
            ->assertHasErrors('form.values.click.service_id');

        expect(Setting::query()->count())->toBe(0);
    });

    it('does not send fields locked by .env', function () {
        freshSecrets(['services.click.service_id' => '777']);
        confirmAdminPassword();

        integrationsScreen()
            ->assertSet('form.values.click.service_id', '777')
            ->set('form.values.click.service_id', '888')
            ->call('save', 'click');

        expect(Setting::query()->where('key', 'click.service_id')->exists())->toBeFalse()
            ->and(config('services.click.service_id'))->toBe('777');
    });

    it('404s for an unknown section', function () {
        confirmAdminPassword();

        integrationsScreen()->call('save', 'stripe')->assertNotFound();
    });
});

describe('click mode', function () {
    it('asks for an extra confirmation before switching Click to prod', function () {
        IntegrationSecret::factory()->withValue('click.secret_key', 'fake-click-live-0000')->create();
        Setting::factory()->create(['key' => 'click.service_id', 'group' => 'integrations', 'value' => '12345']);
        Setting::factory()->create(['key' => 'click.merchant_id', 'group' => 'integrations', 'value' => '11111']);
        Setting::factory()->create(['key' => 'click.merchant_user_id', 'group' => 'integrations', 'value' => '67890']);
        Secrets::flush();
        confirmAdminPassword();

        $component = integrationsScreen()
            ->set('form.values.click.mode', 'prod')
            ->call('save', 'click')
            ->assertDispatched('confirm', method: 'confirmProd')
            ->assertSet('prodConfirmGroup', 'click');

        expect(config('services.click.mode'))->toBe('test');

        $component->call('confirmProd')->assertSet('prodConfirmGroup', null);

        expect(config('services.click.mode'))->toBe('prod');
    });

    it('refuses Click prod without the live credentials', function () {
        confirmAdminPassword();

        integrationsScreen()
            ->set('form.values.click.mode', 'prod')
            ->call('save', 'click')
            ->assertHasErrors('form.values.click.mode')
            ->assertNotDispatched('confirm');

        expect(config('services.click.mode'))->toBe('test');
    });

    it('stores the Click test secret encrypted like the other secrets', function () {
        app(SetSecret::class)->handle('click.test_secret_key', 'fake-click-test-0000', null, null);

        integrationsScreen()->assertSeeHtml('data-secret="click.test_secret_key"')->assertDontSeeHtml('fake-click-test-0000');

        expect(Secrets::isConfigured('click.test_secret_key'))->toBeTrue()
            ->and(config('services.click.test_secret_key'))->toBe('fake-click-test-0000');
    });
});

describe('payme mode', function () {
    it('asks for an extra confirmation before switching to prod', function () {
        IntegrationSecret::factory()->withValue('payme.key', 'payme-fake-live-key-0000')->create();
        Setting::factory()->create(['key' => 'payme.merchant_id', 'group' => 'integrations', 'value' => '587f72c72cac0d162c722ae2']);
        Secrets::flush();
        confirmAdminPassword();

        $component = integrationsScreen()
            ->set('form.values.payme.mode', 'prod')
            ->assertSeeHtml('data-payme-warning')
            ->call('save', 'payme')
            ->assertDispatched('confirm', method: 'confirmProd')
            ->assertSet('prodConfirmGroup', 'payme');

        expect(config('services.payme.mode'))->toBe('test')
            ->and(Setting::query()->where('key', 'payme.mode')->exists())->toBeFalse();

        $component->call('confirmProd')
            ->assertSet('prodConfirmGroup', null)
            ->assertDispatched('toast', type: 'success');

        expect(config('services.payme.mode'))->toBe('prod');
    });

    it('keeps the pending flag on the server', function () {
        confirmAdminPassword();

        integrationsScreen()->call('confirmProd')->assertForbidden();

        expect(fn () => integrationsScreen()->set('prodConfirmGroup', 'payme'))
            ->toThrow(CannotUpdateLockedPropertyException::class);
    });

    it('refuses prod without the merchant ID and the live key', function () {
        confirmAdminPassword();

        integrationsScreen()
            ->set('form.values.payme.mode', 'prod')
            ->call('save', 'payme')
            ->assertHasErrors('form.values.payme.mode')
            ->assertNotDispatched('confirm')
            ->assertSet('prodConfirmGroup', null);

        expect(config('services.payme.mode'))->toBe('test');
    });

    it('warns when test mode has no test key', function () {
        integrationsScreen()->assertSeeHtml('data-payme-test-key-warning');

        IntegrationSecret::factory()->withValue('payme.test_key', 'payme-fake-test-key-0000')->create();
        Secrets::flush();

        integrationsScreen()->assertDontSeeHtml('data-payme-test-key-warning');
    });

    it('warns when the webhook URLs are not public https', function (string $url, bool $warns) {
        config(['app.url' => $url]);

        $component = integrationsScreen();

        $warns ? $component->assertSeeHtml('data-webhooks-not-public') : $component->assertDontSeeHtml('data-webhooks-not-public');
    })->with([
        'http' => ['http://poson.uz', true],
        'localhost' => ['https://localhost', true],
        'loopback' => ['https://127.0.0.1', true],
        'dot test' => ['https://poson.test', true],
        'live' => ['https://poson.uz', false],
    ]);

    it('switches back to test without a confirmation', function () {
        Setting::factory()->create(['key' => 'payme.mode', 'group' => 'integrations', 'value' => 'prod']);
        Secrets::flush();
        confirmAdminPassword();

        integrationsScreen()
            ->set('form.values.payme.mode', 'test')
            ->call('save', 'payme')
            ->assertNotDispatched('confirm');

        expect(config('services.payme.mode'))->toBe('test');
    });

    it('shows the webhook URLs to paste into the merchant cabinets', function () {
        integrationsScreen()
            ->assertSeeHtml('data-webhooks="payme"')
            ->assertSeeHtml(rtrim(config('app.url'), '/').'/webhooks/payme')
            ->assertSeeHtml(rtrim(config('app.url'), '/').'/webhooks/click/prepare')
            ->assertSeeHtml(rtrim(config('app.url'), '/').'/webhooks/click/complete');
    });
});

describe('connection tests', function () {
    it('404s for an unknown or removed target', function (string $target) {
        confirmAdminPassword();

        integrationsScreen()->call('test', $target)->assertNotFound();
    })->with(['payme', 'telegram', 'telegram_chat', 'anthropic', 'openrouter', 'openai', 'removebg']);
});

describe('audit history', function () {
    it('shows the latest 50 changes of all areas, 10 per page, without values', function () {
        $user = staffUser();
        SettingsAudit::factory()->count(55)->sequence(fn ($sequence) => ['key' => 'key_'.$sequence->index])->create(['user_id' => $user->id, 'area' => SettingsArea::Contacts]);
        SettingsAudit::factory()->create(['user_id' => $user->id, 'area' => SettingsArea::Integrations, 'key' => 'telegram.bot_token']);

        $component = integrationsScreen($user)
            ->assertSeeInOrder(['telegram.bot_token', 'key_54'])
            ->assertSee(SettingsArea::Contacts->label())
            ->assertDontSee('key_44');

        $paginator = $component->viewData('audits');

        expect($paginator->total())->toBe(50)
            ->and($paginator->count())->toBe(10)
            ->and($paginator->lastPage())->toBe(5);

        $component->call('gotoPage', 5, 'auditPage')->assertSee('key_6')->assertDontSee('key_5<');
    });

    it('lists a legacy audit row whose area no longer exists (ai_prompts)', function () {
        $user = staffUser();
        DB::table('settings_audits')->insert(['user_id' => $user->id, 'area' => 'ai_prompts', 'key' => 'product.description', 'action' => AuditAction::Updated->value, 'ip' => null, 'created_at' => now()]);
        SettingsAudit::factory()->create(['user_id' => $user->id, 'area' => SettingsArea::Integrations, 'key' => 'telegram.bot_token']);

        expect(SettingsAudit::query()->where('area', 'ai_prompts')->sole()->area)->toBeNull();

        $this->actingAs($user)->get(route('admin.settings.integrations'))
            ->assertOk()
            ->assertSee('product.description')
            ->assertSee('ai_prompts')
            ->assertSee(SettingsArea::Integrations->label());
    });

    it('shows an empty state', function () {
        integrationsScreen()->assertSee(__('admin.settings.integrations.audit.empty'));
    });
});
