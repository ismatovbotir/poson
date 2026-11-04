<?php

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
use App\Support\Integrations\IntegrationRegistry;
use App\Support\Secrets;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Uzqr;

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

beforeEach(function () {
    freshSecrets();
    config(['uzqr.base_urls.test' => Uzqr::TEST]);
});

describe('access', function () use ($otherRoles) {
    it('opens for the admin with every section', function () {
        $html = $this->actingAs(staffUser())
            ->get(route('admin.settings.integrations'))
            ->assertOk()
            ->assertSeeLivewire(Integrations::class)
            ->getContent();

        foreach (['uzqr', 'monitoring', 'indexnow'] as $group) {
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
        $component = integrationsScreen()->set('secretInputs.uzqr.api_key', SCREEN_SECRET);

        $this->actingAs(staffUser($role));

        $component->call($method, ...$params)->assertForbidden();

        expect(IntegrationSecret::query()->count())->toBe(0)
            ->and(Setting::query()->count())->toBe(0)
            ->and(SettingsAudit::query()->count())->toBe(0);
    })->with($otherRoles)->with([
        'save' => ['save', ['uzqr']],
        'replace' => ['replaceSecret', ['uzqr.api_key']],
        'clear' => ['clearSecret', ['uzqr.api_key']],
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
            ->set('secretInputs.uzqr.api_key', SCREEN_SECRET)
            ->set('form.values.uzqr.inn', Uzqr::INN)
            ->call($method, ...$params)
            ->assertRedirect(route('admin.confirm-password'));

        expect(session('url.intended'))->toBe(route('admin.settings.integrations'))
            ->and(IntegrationSecret::query()->count())->toBe(0)
            ->and(Setting::query()->count())->toBe(0);
    })->with([
        'save' => ['save', ['uzqr']],
        'replace' => ['replaceSecret', ['uzqr.api_key']],
        'clear' => ['clearSecret', ['uzqr.api_key']],
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
            ->set('form.values.uzqr.inn', Uzqr::INN)
            ->call('save', 'uzqr')
            ->assertNoRedirect()
            ->assertHasNoErrors();

        expect(config('services.uzqr.inn'))->toBe(Uzqr::INN);

        $this->travel(2)->minutes();

        integrationsScreen()
            ->set('form.values.uzqr.inn', '305123457')
            ->call('save', 'uzqr')
            ->assertRedirect(route('admin.confirm-password'));

        expect(config('services.uzqr.inn'))->toBe(Uzqr::INN);
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

        $component = typeAndReplaceSecret(integrationsScreen(), 'uzqr.api_key', SCREEN_SECRET)
            ->assertHasNoErrors()
            ->assertSet('secretInputs.uzqr.api_key', '')
            ->assertDispatched('toast');

        expect(Secrets::get('uzqr.api_key'))->toBe(SCREEN_SECRET)
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
            ->set('secretInputs.uzqr.api_key', SCREEN_SECRET)
            ->assertSet('secretInputs.uzqr.api_key', '');

        expect(browserPayload($component))->not->toContain('SCREENSECRET');

        $component->set('secretInputs.uzqr.test_api_key', 'fake-uzqr-TYPEDONLY-000000000000000')
            ->set('form.values.uzqr.inn', Uzqr::INN);

        expect(browserPayload($component))->not->toContain('TYPEDONLY')->not->toContain('SCREENSECRET');
    });

    it('renders write-only inputs that browsers must not autofill', function () {
        integrationsScreen()->assertSeeHtml('autocomplete="new-password"')->assertDontSeeHtml('autocomplete="current-password"');
    });

    it('resets the input and keeps the old value on a validation error', function () {
        confirmAdminPassword();

        $component = typeAndReplaceSecret(integrationsScreen(), 'uzqr.api_key', 'broken TOKENPART')
            ->assertHasErrors('secretInputs.uzqr.api_key')
            ->assertSet('secretInputs.uzqr.api_key', '');

        expect(browserPayload($component))->not->toContain('TOKENPART')
            ->and(IntegrationSecret::query()->count())->toBe(0);
    });

    it('shows the status of each secret', function () {
        IntegrationSecret::factory()->withValue('uzqr.test_api_key', 'fake-uzqr-test-0000000000000000000abcd')->create();
        freshSecrets(['services.uzqr.api_key' => 'fake-uzqr-env-0000000000000000000000']);

        integrationsScreen()
            ->assertSeeHtml('data-secret="uzqr.api_key"')
            ->assertSeeInOrder(['data-secret="uzqr.api_key"', 'data-status="env"', 'UZQR_API_KEY'], false)
            ->assertSeeInOrder(['data-secret="uzqr.test_api_key"', 'data-status="configured"', '••••abcd'], false)
            ->assertDontSee('fake-uzqr-env-');
    });

    it('refuses to replace a secret locked by .env', function () {
        freshSecrets(['services.uzqr.api_key' => 'fake-uzqr-env-0000000000000000000000']);
        confirmAdminPassword();

        typeAndReplaceSecret(integrationsScreen(), 'uzqr.api_key', SCREEN_SECRET)
            ->assertDispatched('toast', type: 'error');

        expect(IntegrationSecret::query()->count())->toBe(0);
    });

    it('clears a secret', function () {
        IntegrationSecret::factory()->withValue('uzqr.test_api_key', 'fake-uzqr-secret-00000000')->create();
        Secrets::flush();
        confirmAdminPassword();

        integrationsScreen()->call('clearSecret', 'uzqr.test_api_key')->assertDispatched('toast', type: 'success');

        expect(Secrets::isConfigured('uzqr.test_api_key'))->toBeFalse()
            ->and(SettingsAudit::query()->sole()->action)->toBe(AuditAction::Cleared);
    });

    it('404s for a key that is not a secret', function () {
        confirmAdminPassword();

        integrationsScreen()->call('replaceSecret', 'uzqr.mode')->assertNotFound();
    });
});

describe('settings', function () {
    it('saves a section and refills the form', function () {
        confirmAdminPassword();

        integrationsScreen()
            ->set('form.values.uzqr.inn', Uzqr::INN)
            ->call('save', 'uzqr')
            ->assertHasNoErrors()
            ->assertSet('form.values.uzqr.inn', Uzqr::INN)
            ->assertDispatched('toast', type: 'success');

        expect(config('services.uzqr.inn'))->toBe(Uzqr::INN)
            ->and(SettingsAudit::query()->where('area', SettingsArea::Integrations)->pluck('key')->sort()->values()->all())->toBe(['uzqr.inn']);
    });

    it('shows validation errors under the field', function () {
        confirmAdminPassword();

        integrationsScreen()
            ->set('form.values.uzqr.inn', 'not digits')
            ->call('save', 'uzqr')
            ->assertHasErrors('form.values.uzqr.inn');

        expect(Setting::query()->count())->toBe(0);
    });

    it('does not send fields locked by .env', function () {
        freshSecrets(['services.uzqr.inn' => '777777777']);
        confirmAdminPassword();

        integrationsScreen()
            ->assertSet('form.values.uzqr.inn', '777777777')
            ->set('form.values.uzqr.inn', '888888888')
            ->call('save', 'uzqr');

        expect(Setting::query()->where('key', 'uzqr.inn')->exists())->toBeFalse()
            ->and(config('services.uzqr.inn'))->toBe('777777777');
    });

    it('404s for an unknown section', function () {
        confirmAdminPassword();

        integrationsScreen()->call('save', 'stripe')->assertNotFound();
    });
});

describe('hidden payment providers (plan 23)', function () {
    it('renders neither Payme nor Click: no sections, chips, fields or webhook URLs', function () {
        IntegrationSecret::factory()->withValue('payme.key', 'fake-payme-HIDDENVALUE-0000')->create();
        Secrets::flush();

        $html = integrationsScreen()->html();

        expect($html)->not->toContain('data-section="payme"')->not->toContain('data-section="click"')
            ->not->toContain('#section-payme')->not->toContain('#section-click')
            ->not->toContain('data-secret="payme.')->not->toContain('data-secret="click.')
            ->not->toContain('data-webhooks')->not->toContain('/webhooks/payme')->not->toContain('/webhooks/click')
            ->not->toContain('HIDDENVALUE')
            ->not->toMatch('/\\b(Payme|Click)\\b/')
            ->toContain('#section-uzqr')->toContain(route('admin.telegram.settings'));
    });

    it('answers 404 to every write and test for the hidden groups and leaves stored rows alone', function (string $method, array $params) {
        IntegrationSecret::factory()->withValue('payme.key', 'fake-payme-stored-0000')->create();
        Setting::factory()->create(['key' => 'click.service_id', 'group' => 'integrations', 'value' => '12345']);
        Secrets::flush();
        confirmAdminPassword();
        $before = [IntegrationSecret::query()->get()->toArray(), Setting::query()->get()->toArray(), SettingsAudit::query()->count()];

        integrationsScreen()
            ->set('form.values.click.service_id', '99999')
            ->call($method, ...$params)
            ->assertNotFound();

        expect([IntegrationSecret::query()->get()->toArray(), Setting::query()->get()->toArray(), SettingsAudit::query()->count()])->toBe($before)
            ->and(Secrets::get('payme.key'))->toBe('fake-payme-stored-0000');
    })->with([
        'save payme' => ['save', ['payme']],
        'save click' => ['save', ['click']],
        'replace payme key' => ['replaceSecret', ['payme.key']],
        'replace click secret' => ['replaceSecret', ['click.secret_key']],
        'clear payme key' => ['clearSecret', ['payme.key']],
        'clear click test secret' => ['clearSecret', ['click.test_secret_key']],
        'test payme' => ['test', ['payme']],
        'test click' => ['test', ['click']],
    ]);

    it('keeps the registry groups and fields untouched', function () {
        expect(IntegrationRegistry::GROUPS)->toContain('payme', 'click')
            ->and(IntegrationRegistry::has('payme.key'))->toBeTrue()
            ->and(Integrations::PAYMENT_GROUPS)->toBe(['uzqr']);
    });

    it('asks for an extra confirmation before switching UzQR to prod and then switches', function () {
        Uzqr::configure('test', ['services.uzqr.mode' => null]);
        confirmAdminPassword();

        $component = integrationsScreen()
            ->set('form.values.uzqr.mode', 'prod')
            ->call('save', 'uzqr')
            ->assertDispatched('confirm', method: 'confirmProd')
            ->assertSet('prodConfirmGroup', 'uzqr');

        expect(Setting::query()->where('key', 'uzqr.mode')->exists())->toBeFalse();

        $component->call('confirmProd')->assertSet('prodConfirmGroup', null)->assertDispatched('toast', type: 'success');

        expect(Secrets::get('uzqr.mode'))->toBe('prod');
    });

    it('keeps the pending flag on the server and refuses a hidden group in confirmProd', function () {
        confirmAdminPassword();

        integrationsScreen()->call('confirmProd')->assertForbidden();

        expect(fn () => integrationsScreen()->set('prodConfirmGroup', 'uzqr'))->toThrow(CannotUpdateLockedPropertyException::class);
    });
});

describe('connection tests', function () {
    it('404s for an unknown or removed target', function (string $target) {
        confirmAdminPassword();

        integrationsScreen()->call('test', $target)->assertNotFound();
    })->with(['payme', 'click', 'telegram', 'telegram_chat', 'anthropic', 'openrouter', 'openai', 'removebg']);
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
