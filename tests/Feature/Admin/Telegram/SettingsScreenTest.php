<?php

use App\Actions\Settings\GenerateWebhookSecret;
use App\Actions\Settings\TestIntegrationConnection;
use App\Enums\ActivityType;
use App\Enums\AuditAction;
use App\Enums\IntegrationSource;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Livewire\Admin\Concerns\ManagesIntegrationSettings;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Settings\Integrations;
use App\Livewire\Admin\Telegram\Settings;
use App\Models\IntegrationSecret;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\Admin\Menu;
use App\Support\Admin\MenuGroup;
use App\Support\Admin\MenuItem;
use App\Support\Secrets;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Js;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

const TG_SETTINGS_TOKEN = '123456789:AAScreenTokenScreenTokenScreenTok4321';

const TG_SETTINGS_WEBHOOK_URL = 'https://poson.example.uz/api/telegram/webhook';

function telegramSettingsScreen(?User $user = null): Testable
{
    return Livewire::actingAs($user ?? staffUser())->test(Settings::class);
}

/**
 * Type a secret and press its Save button: one round trip, like the browser.
 */
function typeAndReplaceTelegramSecret(Testable $component, string $key, string $value): Testable
{
    return $component->update(
        calls: [['method' => 'replaceSecret', 'params' => [$key], 'path' => '']],
        updates: ['secretInputs.'.$key => $value],
    );
}

/**
 * Everything the browser receives for a component: HTML, snapshot and effects.
 */
function telegramSettingsPayload(Testable $component): string
{
    return $component->html().json_encode($component->snapshot).json_encode($component->effects);
}

/**
 * The bot token is saved in admin; getWebhookInfo answers with our URL.
 */
function telegramSettingsWebhookFake(): void
{
    Http::fake(['api.telegram.org/*' => fn ($request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/getWebhookInfo')
        ? Http::response(['ok' => true, 'result' => ['url' => TG_SETTINGS_WEBHOOK_URL, 'pending_update_count' => 0]])
        : Http::response(['ok' => true, 'result' => true])]);
}

/**
 * A user holding the Telegram permissions, but not settings.integrations.
 */
function telegramOperatorWithoutSettings(): User
{
    $user = staffUser(Role::SalesManager);
    $user->givePermissionTo(['telegram.bot', 'telegram.inbox']);

    return $user->refresh();
}

$everyAction = [
    'save' => ['save', ['telegram']],
    'replace' => ['replaceSecret', ['telegram.bot_token']],
    'clear' => ['clearSecret', ['telegram.bot_token']],
    'generate' => ['generateWebhookSecret', []],
    'test' => ['test', ['telegram']],
    'test chat' => ['test', ['telegram_chat', 'sales']],
];

beforeEach(fn () => freshSecrets());

describe('access', function () use ($everyAction) {
    it('opens for the admin with the Telegram section only', function () {
        $html = $this->actingAs(staffUser())
            ->get(route('admin.telegram.settings'))
            ->assertOk()
            ->assertSeeLivewire(Settings::class)
            ->getContent();

        expect($html)->toContain('data-section="telegram"')
            ->and($html)->not->toMatch('/data-section="(payme|click|uzqr|monitoring|indexnow)"/')
            ->and($html)->not->toMatch('/data-(secret|setting)="(payme|click|uzqr|monitoring|indexnow)\./');
    });

    it('forbids every other staff role', function (Role $role) {
        $this->actingAs(staffUser($role))->get(route('admin.telegram.settings'))->assertForbidden();
    })->with([Role::ContentManager, Role::SalesManager, Role::ServiceEngineer]);

    it('forbids telegram.bot + telegram.inbox without settings.integrations', function () {
        $this->actingAs(telegramOperatorWithoutSettings())->get(route('admin.telegram.settings'))->assertForbidden();
    });

    it('forbids every action by direct call to telegram.bot + telegram.inbox without settings.integrations', function (string $method, array $params) {
        confirmAdminPassword();
        $component = telegramSettingsScreen();

        $this->actingAs(telegramOperatorWithoutSettings());

        $component->call($method, ...$params)->assertForbidden();

        expect(IntegrationSecret::query()->count())->toBe(0)
            ->and(Setting::query()->count())->toBe(0)
            ->and(SettingsAudit::query()->count())->toBe(0);
    })->with($everyAction);

    it('forbids the password redirect and paging too', function (string $method, array $params) {
        $component = telegramSettingsScreen();

        $this->actingAs(telegramOperatorWithoutSettings());

        $component->call($method, ...$params)->assertForbidden();
    })->with([
        'confirm password' => ['confirmPassword', []],
        'page' => ['gotoPage', [2, 'auditPage']],
    ]);

    it('forbids every action by direct call to Sales', function (string $method, array $params) {
        confirmAdminPassword();
        $component = telegramSettingsScreen();

        $this->actingAs(staffUser(Role::SalesManager));

        $component->call($method, ...$params)->assertForbidden();

        expect(SettingsAudit::query()->count())->toBe(0);
    })->with($everyAction);

    it('shows the menu item by the settings.integrations permission', function () {
        $item = collect(Menu::definition())
            ->flatMap(fn (MenuGroup $group): array => $group->items)
            ->first(fn (MenuItem $item): bool => $item->key === 'telegram-settings');

        expect($item->permission)->toBe('settings.integrations')
            ->and($item->isAvailable())->toBeTrue()
            ->and($item->isVisibleTo(staffUser()))->toBeTrue()
            ->and($item->isVisibleTo(telegramOperatorWithoutSettings()))->toBeFalse();
    });
});

describe('T21-1 scope', function () {
    it('404s for the groups, keys and targets of other screens, before asking for the password', function (string $method, array $params) {
        telegramSettingsScreen()->call($method, ...$params)->assertNotFound()->assertNoRedirect();

        confirmAdminPassword();

        telegramSettingsScreen()->call($method, ...$params)->assertNotFound();

        expect(SettingsAudit::query()->count())->toBe(0);
    })->with([
        'save payme' => ['save', ['payme']],
        'save click' => ['save', ['click']],
        'save uzqr' => ['save', ['uzqr']],
        'save monitoring' => ['save', ['monitoring']],
        'save indexnow' => ['save', ['indexnow']],
        'save unknown' => ['save', ['stripe']],
        'replace payme' => ['replaceSecret', ['payme.key']],
        'replace click' => ['replaceSecret', ['click.secret_key']],
        'replace uzqr' => ['replaceSecret', ['uzqr.api_key']],
        'replace monitoring' => ['replaceSecret', ['monitoring.ping_url']],
        'replace indexnow' => ['replaceSecret', ['indexnow.key']],
        'replace a non-secret' => ['replaceSecret', ['telegram.chats.sales']],
        'clear payme' => ['clearSecret', ['payme.key']],
        'clear indexnow' => ['clearSecret', ['indexnow.key']],
        'test uzqr' => ['test', ['uzqr']],
        'test indexnow' => ['test', ['indexnow']],
        'test unknown' => ['test', ['payme']],
        'test unknown chat' => ['test', ['telegram_chat', 'nope']],
        'test no chat' => ['test', ['telegram_chat']],
    ]);

    it('leaves a stored secret of another group untouched', function () {
        IntegrationSecret::factory()->withValue('payme.key', 'fake-payme-key-0000000')->create();
        Secrets::flush();
        confirmAdminPassword();

        telegramSettingsScreen()->call('clearSecret', 'payme.key')->assertNotFound();

        expect(Secrets::isConfigured('payme.key'))->toBeTrue();
    });

    it('has no payment or IndexNow actions and no prod confirmation state', function (string $method) {
        confirmAdminPassword();

        expect(fn () => telegramSettingsScreen()->call($method))->toThrow(MethodNotFoundException::class);
    })->with(['confirmProd', 'generateIndexNowKey']);

    it('has no prodConfirmGroup property', function () {
        expect(property_exists(Settings::class, 'prodConfirmGroup'))->toBeFalse()
            ->and(telegramSettingsScreen()->snapshot['data'])->not->toHaveKey('prodConfirmGroup');
    });

    it('has no generateWebhookSecret on Integrations', function () {
        confirmAdminPassword();

        expect(fn () => Livewire::actingAs(staffUser())->test(Integrations::class)->call('generateWebhookSecret'))
            ->toThrow(MethodNotFoundException::class);

        expect(Secrets::isConfigured('telegram.webhook_secret'))->toBeFalse();
    });

    it('404s for Telegram groups, keys and targets on Integrations (a stale tab), before asking for the password', function (string $method, array $params) {
        Livewire::actingAs(staffUser())->test(Integrations::class)->call($method, ...$params)->assertNotFound()->assertNoRedirect();

        confirmAdminPassword();

        Livewire::actingAs(staffUser())->test(Integrations::class)->call($method, ...$params)->assertNotFound();

        expect(SettingsAudit::query()->count())->toBe(0);
    })->with([
        'save' => ['save', ['telegram']],
        'replace token' => ['replaceSecret', ['telegram.bot_token']],
        'clear token' => ['clearSecret', ['telegram.bot_token']],
        'clear webhook secret' => ['clearSecret', ['telegram.webhook_secret']],
        'test' => ['test', ['telegram']],
        'test chat' => ['test', ['telegram_chat', 'sales']],
    ]);

    it('exposes only the listed public methods from the trait', function () {
        $public = collect((new ReflectionClass(ManagesIntegrationSettings::class))->getMethods(ReflectionMethod::IS_PUBLIC))
            ->pluck('name')->sort()->values()->all();

        expect($public)->toBe(['boot', 'clearSecret', 'confirmPassword', 'dehydrate', 'mount', 'replaceSecret', 'save', 'test']);
    });

    it('refuses a direct call to any helper', function (string $class, string $method) {
        expect(fn () => Livewire::actingAs(staffUser())->test($class)->call($method))->toThrow(MethodNotFoundException::class);
    })->with(['telegram page' => [Settings::class], 'integrations' => [Integrations::class]])->with([
        'integrationGroups', 'allowedTestTargets', 'returnRouteName', 'interceptSave', 'persist', 'testId',
        'fieldStates', 'paginateAudits', 'secretField', 'ensurePasswordConfirmed', 'resetSecretInputs', 'audits',
    ]);
});

describe('T21-2 data scope', function () {
    it('holds no value of another group on the new page', function () {
        foreach (['payme.key' => 'fake-PAYMEMARK-0000', 'click.secret_key' => 'fake-CLICKMARK-0000', 'uzqr.api_key' => 'fake-UZQRMARK-00000000', 'monitoring.ping_url' => 'https://hc-ping.com/PINGMARK', 'indexnow.key' => 'INDEXMARK00000000000000000000000000'] as $key => $value) {
            IntegrationSecret::factory()->withValue($key, $value)->create();
        }
        Setting::factory()->create(['key' => 'payme.merchant_id', 'group' => 'integrations', 'value' => 'merchantmark00000000aaaa']);
        Setting::factory()->create(['key' => 'click.service_id', 'group' => 'integrations', 'value' => '77770001']);
        Setting::factory()->create(['key' => 'uzqr.inn', 'group' => 'integrations', 'value' => '777700020']);
        Secrets::flush();
        confirmAdminPassword();

        $component = telegramSettingsScreen();
        $data = $component->snapshot['data'];

        expect(json_encode($data))->not->toMatch('/"(payme|click|uzqr|monitoring|indexnow)"/')
            ->and($component->get('form.values'))->toHaveKeys(['telegram'])->toHaveCount(1)
            ->and($component->get('secretInputs'))->toHaveKeys(['telegram'])->toHaveCount(1);

        // Refills after a save and an update stay scoped.
        $component->call('save', 'telegram')->call('test', 'telegram');

        expect($component->get('form.values'))->toHaveCount(1)
            ->and($component->get('secretInputs'))->toHaveCount(1)
            ->and($component->viewData('sections'))->toHaveKeys(['telegram'])->toHaveCount(1);

        foreach (['PAYMEMARK', 'CLICKMARK', 'UZQRMARK', 'PINGMARK', 'INDEXMARK', 'merchantmark', '77770001', '777700020'] as $marker) {
            expect(telegramSettingsPayload($component))->not->toContain($marker);
        }
    });

    it('passes no payment, IndexNow or webhook data to the view', function () {
        $data = [];
        View::composer('livewire.admin.telegram.settings', function ($view) use (&$data): void {
            $data = $view->getData();
        });

        telegramSettingsScreen();

        expect($data)->toHaveKey('sections')
            ->not->toHaveKeys(['uzqr'])
            ->not->toHaveKeys(['indexnow'])
            ->not->toHaveKeys(['webhookUrls'])
            ->not->toHaveKeys(['prodGroups'])
            ->not->toHaveKeys(['testKeyMissing']);
    });

    it('holds no Telegram value or chat id on Integrations', function () {
        IntegrationSecret::factory()->withValue('telegram.bot_token', TG_SETTINGS_TOKEN)->create();
        IntegrationSecret::factory()->withValue('telegram.webhook_secret', str_repeat('HOOKMARK', 6))->create();
        Setting::factory()->create(['key' => 'telegram.chats.sales', 'group' => 'integrations', 'value' => '-100555000111']);
        Secrets::flush();
        confirmAdminPassword();

        $component = Livewire::actingAs(staffUser())->test(Integrations::class);

        expect(json_encode($component->snapshot['data']))->not->toContain('telegram')
            ->and($component->get('form.values'))->not->toHaveKey('telegram')
            ->and($component->get('secretInputs'))->not->toHaveKey('telegram')
            ->and($component->viewData('sections'))->not->toHaveKey('telegram')
            ->and($component->html())->not->toMatch('/data-(section|secret|setting)="telegram/')
            ->and(telegramSettingsPayload($component))->not->toContain('AAScreenToken')->not->toContain('HOOKMARK')->not->toContain('100555000111');
    });

    it('lists only Telegram changes of the integrations area, never a value', function () {
        $user = staffUser();
        SettingsAudit::factory()->create(['user_id' => $user->id, 'area' => SettingsArea::Integrations, 'key' => 'telegram.bot_token']);
        SettingsAudit::factory()->create(['user_id' => $user->id, 'area' => SettingsArea::Integrations, 'key' => 'payme.key']);
        SettingsAudit::factory()->create(['user_id' => $user->id, 'area' => SettingsArea::TelegramBot, 'key' => 'telegram.bot_enabled']);

        $component = telegramSettingsScreen($user)
            ->assertSeeHtml('telegram.bot_token')
            ->assertDontSeeHtml('payme.key')
            ->assertDontSeeHtml('telegram.bot_enabled');

        expect($component->viewData('audits')->total())->toBe(1);
    });

    it('caps and pages the history', function () {
        $user = staffUser();
        SettingsAudit::factory()->count(55)->sequence(fn ($sequence) => ['key' => 'telegram.key_'.$sequence->index])->create(['user_id' => $user->id, 'area' => SettingsArea::Integrations]);

        $paginator = telegramSettingsScreen($user)->viewData('audits');

        expect($paginator->total())->toBe(Settings::AUDIT_LIMIT)
            ->and($paginator->count())->toBe(Settings::AUDIT_PER_PAGE);
    });

    it('shows an empty history', function () {
        telegramSettingsScreen()->assertSee(__('admin.settings.integrations.audit.empty'));
    });
});

describe('T21-3 password', function () use ($everyAction) {
    it('asks for the password before every action and returns to the new page', function (string $method, array $params) {
        Http::fake();

        telegramSettingsScreen()
            ->set('form.values.telegram.chats.sales', '-1001234567890')
            ->call($method, ...$params)
            ->assertRedirect(route('admin.confirm-password'));

        expect(session('url.intended'))->toBe(route('admin.telegram.settings'))
            ->and(IntegrationSecret::query()->count())->toBe(0)
            ->and(Setting::query()->count())->toBe(0)
            ->and(SettingsAudit::query()->count())->toBe(0);

        Http::assertNothingSent();
    })->with($everyAction);

    it('sends the confirm button back to the new page', function () {
        telegramSettingsScreen()->call('confirmPassword')->assertRedirect(route('admin.confirm-password'));

        expect(session('url.intended'))->toBe(route('admin.telegram.settings'));
    });

    it('shows the confirmation banner and disables the forms until confirmed', function () {
        telegramSettingsScreen()
            ->assertSeeHtml('data-password-required')
            ->assertSeeHtml('<fieldset class="mt-5 max-w-4xl space-y-4 pb-10 lg:space-y-6" disabled');

        confirmAdminPassword();

        telegramSettingsScreen()
            ->assertSeeHtml('data-password-confirmed')
            ->assertDontSeeHtml('data-password-required');
    });

    it('accepts a confirmation from the last 15 minutes and expires after that', function () {
        confirmAdminPassword(14 * 60);

        telegramSettingsScreen()
            ->set('form.values.telegram.chats.sales', '-1001234567890')
            ->call('save', 'telegram')
            ->assertNoRedirect()
            ->assertHasNoErrors();

        expect(config('services.telegram.chats.sales'))->toBe('-1001234567890');

        $this->travel(2)->minutes();

        telegramSettingsScreen()
            ->set('form.values.telegram.chats.sales', '-1009999999999')
            ->call('save', 'telegram')
            ->assertRedirect(route('admin.confirm-password'));

        expect(config('services.telegram.chats.sales'))->toBe('-1001234567890');
    });
});

describe('T21-3 secrets', function () {
    it('saves the token and never sends it back to the browser, logs or audit', function () {
        confirmAdminPassword();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.json_encode($event->context);
        });

        $component = typeAndReplaceTelegramSecret(telegramSettingsScreen(), 'telegram.bot_token', TG_SETTINGS_TOKEN)
            ->assertHasNoErrors()
            ->assertSet('secretInputs.telegram.bot_token', '')
            ->assertDispatched('toast', type: 'success');

        $audit = SettingsAudit::query()->sole();

        expect(Secrets::get('telegram.bot_token'))->toBe(TG_SETTINGS_TOKEN)
            ->and(telegramSettingsPayload($component))->not->toContain('AAScreenToken')
            ->and($component->html())->toContain('••••4321')
            ->and($audit->area)->toBe(SettingsArea::Integrations)
            ->and($audit->key)->toBe('telegram.bot_token')
            ->and(json_encode($audit->toArray()))->not->toContain('AAScreenToken')
            ->and(implode("\n", $logged))->not->toContain('AAScreenToken');

        $page = $this->actingAs(staffUser())->get(route('admin.telegram.settings'))->assertOk()->getContent();

        expect($page)->not->toContain('AAScreenToken')->toContain('••••4321');
    });

    it('never renders a saved secret', function () {
        IntegrationSecret::factory()->withValue('telegram.bot_token', TG_SETTINGS_TOKEN)->create();
        IntegrationSecret::factory()->withValue('telegram.webhook_secret', str_repeat('DDDDFOUR', 6))->create();
        Secrets::flush();
        confirmAdminPassword();

        expect(telegramSettingsPayload(telegramSettingsScreen()))->not->toContain('AAScreenToken')->not->toContain('DDDDFOUR');
    });

    it('resets typed secrets after every request', function () {
        confirmAdminPassword();

        $component = telegramSettingsScreen()
            ->set('secretInputs.telegram.bot_token', '999:TYPEDONLY')
            ->assertSet('secretInputs.telegram.bot_token', '');

        expect(telegramSettingsPayload($component))->not->toContain('TYPEDONLY');

        $component->set('form.values.telegram.chats.sales', '-100123')
            ->assertSet('secretInputs.telegram.bot_token', '');

        expect(telegramSettingsPayload($component))->not->toContain('TYPEDONLY');
    });

    it('resets a typed value of another group, which has no input here', function () {
        $component = telegramSettingsScreen()->set('secretInputs.payme.key', 'fake-FORGEDINPUT-000000');

        expect(telegramSettingsPayload($component))->not->toContain('FORGEDINPUT')
            ->and($component->get('secretInputs'))->not->toHaveKey('payme');
    });

    it('resets the input and keeps the old value on a validation error', function () {
        confirmAdminPassword();

        $component = typeAndReplaceTelegramSecret(telegramSettingsScreen(), 'telegram.bot_token', 'broken-TOKENPART')
            ->assertHasErrors('secretInputs.telegram.bot_token')
            ->assertSet('secretInputs.telegram.bot_token', '');

        expect(telegramSettingsPayload($component))->not->toContain('TOKENPART')
            ->and(IntegrationSecret::query()->count())->toBe(0);
    });

    it('renders write-only inputs that browsers must not autofill', function () {
        telegramSettingsScreen()->assertSeeHtml('autocomplete="new-password"')->assertDontSeeHtml('autocomplete="current-password"');
    });

    it('refuses replaceSecret on generated fields, on both screens', function () {
        confirmAdminPassword();

        typeAndReplaceTelegramSecret(telegramSettingsScreen(), 'telegram.webhook_secret', str_repeat('a', 48))->assertNotFound();

        $integrations = Livewire::actingAs(staffUser())->test(Integrations::class);

        typeAndReplaceTelegramSecret($integrations, 'indexnow.key', str_repeat('b', 48))->assertNotFound();

        expect(IntegrationSecret::query()->count())->toBe(0)
            ->and(SettingsAudit::query()->count())->toBe(0);
    });

    it('clears a secret with an audit row', function () {
        IntegrationSecret::factory()->withValue('telegram.bot_token', TG_SETTINGS_TOKEN)->create();
        Secrets::flush();
        confirmAdminPassword();

        telegramSettingsScreen()->call('clearSecret', 'telegram.bot_token')->assertDispatched('toast', type: 'success');

        $audit = SettingsAudit::query()->sole();

        expect(Secrets::isConfigured('telegram.bot_token'))->toBeFalse()
            ->and($audit->action)->toBe(AuditAction::Cleared)
            ->and($audit->area)->toBe(SettingsArea::Integrations)
            ->and(json_encode($audit->toArray()))->not->toContain('AAScreenToken');
    });

    it('shows and refuses fields locked by .env', function () {
        freshSecrets(['services.telegram.bot_token' => TG_SETTINGS_TOKEN, 'services.telegram.chats.sales' => '-100777']);
        confirmAdminPassword();

        $component = telegramSettingsScreen()
            ->assertSeeInOrder(['data-secret="telegram.bot_token"', 'data-status="env"', 'TELEGRAM_BOT_TOKEN'], false)
            ->assertSeeInOrder(['data-setting="telegram.chats.sales"', 'disabled'], false)
            ->assertSet('form.values.telegram.chats.sales', '-100777');

        expect(telegramSettingsPayload($component))->not->toContain('AAScreenToken');

        typeAndReplaceTelegramSecret($component, 'telegram.bot_token', '123456789:AAOtherTokenOtherTokenOtherTokenOt0000')
            ->assertDispatched('toast', type: 'error');

        $component->set('form.values.telegram.chats.sales', '-100888')->call('save', 'telegram');

        expect(IntegrationSecret::query()->count())->toBe(0)
            ->and(Setting::query()->where('key', 'telegram.chats.sales')->exists())->toBeFalse()
            ->and(config('services.telegram.chats.sales'))->toBe('-100777')
            ->and(Secrets::source('telegram.bot_token'))->toBe(IntegrationSource::Env);
    });
});

describe('T21-3 generate webhook secret', function () {
    it('commits the secret, then repoints the webhook with it, without exposing anything', function () {
        confirmAdminPassword();
        telegramBotConfigured(['services.telegram.webhook_secret' => null]);
        telegramSettingsWebhookFake();

        $component = telegramSettingsScreen()
            ->call('generateWebhookSecret')
            ->assertDispatched('toast', type: 'success')
            ->assertNotDispatched('toast', type: 'error');

        $secret = Secrets::get('telegram.webhook_secret');
        $call = collect(telegramApiCalls())->firstWhere('method', 'setWebhook');
        $audit = SettingsAudit::query()->where('key', GenerateWebhookSecret::KEY)->sole();
        $payload = telegramSettingsPayload($component);

        expect($secret)->toMatch('/^[A-Za-z0-9]{48}$/')
            ->and($call['data']['secret_token'])->toBe($secret)
            ->and($audit->action)->toBe(AuditAction::Generated)
            ->and($audit->area)->toBe(SettingsArea::Integrations)
            ->and(json_encode(SettingsAudit::query()->get()->toArray()))->not->toContain($secret)
            ->and($payload)->not->toContain($secret)
            ->and($payload)->not->toContain(TG_BOT_TOKEN)
            ->and($payload)->not->toContain(TG_SETTINGS_WEBHOOK_URL)
            ->and($payload)->not->toContain('api.telegram.org');
    });

    it('keeps the new secret and shows a warning toast when repointing fails', function () {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 500)]);
        confirmAdminPassword();
        telegramBotConfigured(['services.telegram.webhook_secret' => null]);

        $component = telegramSettingsScreen()
            ->call('generateWebhookSecret')
            ->assertDispatched('toast', type: 'success')
            ->assertDispatched('toast', type: 'error');

        expect(Secrets::isConfigured('telegram.webhook_secret'))->toBeTrue()
            ->and(telegramSettingsPayload($component))->not->toContain(TG_BOT_TOKEN)
            ->not->toContain(Secrets::get('telegram.webhook_secret'))
            ->not->toContain('api.telegram.org');
    });

    it('refuses while the secret is locked by .env, and does not call Telegram', function () {
        Http::fake();
        confirmAdminPassword();
        telegramBotConfigured();

        telegramSettingsScreen()->call('generateWebhookSecret')->assertDispatched('toast', type: 'error');

        expect(IntegrationSecret::query()->count())->toBe(0)
            ->and(SettingsAudit::query()->count())->toBe(0);

        Http::assertNothingSent();
    });
});

describe('settings', function () {
    it('saves the section with audit rows in the integrations area and no value', function () {
        confirmAdminPassword();

        telegramSettingsScreen()
            ->set('form.values.telegram.notify_new_leads', false)
            ->set('form.values.telegram.locale', 'ru')
            ->set('form.values.telegram.chats.inbox', '-1002222222222')
            ->call('save', 'telegram')
            ->assertHasNoErrors()
            ->assertSet('form.values.telegram.locale', 'ru')
            ->assertDispatched('toast', type: 'success');

        $audits = SettingsAudit::query()->get();

        expect(config('services.telegram.notify_new_leads'))->toBeFalse()
            ->and(config('services.telegram.locale'))->toBe('ru')
            ->and($audits->pluck('area')->unique()->all())->toBe([SettingsArea::Integrations])
            ->and($audits->pluck('key')->sort()->values()->all())->toBe(['telegram.chats.inbox', 'telegram.locale', 'telegram.notify_new_leads'])
            ->and(json_encode($audits->toArray()))->not->toContain('1002222222222');
    });

    it('shows validation errors under the field', function () {
        confirmAdminPassword();

        telegramSettingsScreen()
            ->set('form.values.telegram.chats.sales', 'sales chat')
            ->call('save', 'telegram')
            ->assertHasErrors('form.values.telegram.chats.sales');

        expect(Setting::query()->count())->toBe(0);
    });
});

describe('connection tests', function () {
    it('makes no Telegram call on load', function () {
        Http::fake();

        telegramBotConfigured();
        telegramSettingsScreen();

        Http::assertNothingSent();
    });

    it('tests the saved token and shows a safe result', function () {
        IntegrationSecret::factory()->withValue('telegram.bot_token', TG_SETTINGS_TOKEN)->create();
        Secrets::flush();
        confirmAdminPassword();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 401)]);

        $component = telegramSettingsScreen()
            ->set('secretInputs.telegram.bot_token', '999:typed-but-not-saved')
            ->call('test', 'telegram')
            ->assertSet('testResults.telegram_bot_token.ok', false)
            ->assertSeeHtml('data-test-result');

        expect(telegramSettingsPayload($component))->not->toContain('AAScreenToken')->not->toContain('typed-but-not-saved');
    });

    it('sends a test message to each of the five chats', function (string $chat) {
        IntegrationSecret::factory()->withValue('telegram.bot_token', TG_SETTINGS_TOKEN)->create();
        Setting::factory()->create(['key' => 'telegram.chats.'.$chat, 'group' => 'integrations', 'value' => '-100123']);
        Secrets::flush();
        confirmAdminPassword();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        telegramSettingsScreen()
            ->assertSeeHtml('data-test="telegram_chat_'.$chat.'"')
            ->call('test', 'telegram_chat', $chat)
            ->assertSet('testResults.telegram_chats_'.$chat.'.ok', true);

        expect(collect(telegramApiCalls())->firstWhere('method', 'sendMessage')['data']['chat_id'])->toBe('-100123');
    })->with(TestIntegrationConnection::CHATS);

    it('is rate limited', function () {
        $user = staffUser();
        IntegrationSecret::factory()->withValue('telegram.bot_token', TG_SETTINGS_TOKEN)->create();
        Secrets::flush();
        confirmAdminPassword();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['username' => 'poson_bot']])]);

        $component = telegramSettingsScreen($user);

        foreach (range(1, TestIntegrationConnection::MAX_ATTEMPTS) as $attempt) {
            $component->call('test', 'telegram')->assertSet('testResults.telegram_bot_token.ok', true);
        }

        $component->call('test', 'telegram')->assertSet('testResults.telegram_bot_token.ok', false);

        Http::assertSentCount(TestIntegrationConnection::MAX_ATTEMPTS);
    });

    it('uses no rate limit while the password is not confirmed', function () {
        $user = staffUser();
        $component = telegramSettingsScreen($user);

        foreach (range(1, TestIntegrationConnection::MAX_ATTEMPTS + 1) as $attempt) {
            $component->call('test', 'telegram')->assertRedirect(route('admin.confirm-password'));
        }

        expect(RateLimiter::attempts(TestIntegrationConnection::throttleKey($user)))->toBe(0);
    });
});

describe('T21-4 links', function () {
    it('redirects only the old #section-telegram anchor, to a server-defined URL', function () {
        $html = $this->actingAs(staffUser())->get(route('admin.settings.integrations'))->assertOk()->getContent();

        $expected = "x-init=\"if (location.hash === '#section-telegram') location.replace(".Js::from(route('admin.telegram.settings'))->toHtml().')"';

        expect($html)->toContain($expected)
            ->and(substr_count($html, 'location.hash'))->toBe(1)
            ->and(substr_count($html, 'location.replace'))->toBe(1);
    });

    it('turns the Telegram chip into a link to the new page', function () {
        Livewire::actingAs(staffUser())->test(Integrations::class)
            ->assertSeeHtml('href="'.route('admin.telegram.settings').'"')
            ->assertDontSeeHtml('href="#section-telegram"')
            ->assertSeeHtml('href="#section-payme"');
    });

    it('keeps the other sections of Integrations open', function () {
        $html = $this->actingAs(staffUser())->get(route('admin.settings.integrations'))->assertOk()->getContent();

        foreach (['payme', 'click', 'uzqr', 'monitoring', 'indexnow'] as $group) {
            expect($html)->toContain('data-section="'.$group.'"');
        }
    });

    it('points the dashboard failure alert to the new page, without the anchor', function () {
        $admin = staffUser();
        Lead::factory()->create()->activities()->create(['type' => ActivityType::Notification, 'meta' => ['result' => 'failed', 'chat' => 'sales', 'code' => '403'], 'created_at' => now()]);

        Livewire::actingAs($admin)->test(Dashboard::class)
            ->assertSeeHtml('data-telegram-failures')
            ->assertSeeHtml('href="'.route('admin.telegram.settings').'"')
            ->assertDontSeeHtml('#section-telegram');
    });

    it('points the MCP management-chat alert to the new page', function () {
        $this->actingAs(staffUser())->get(route('admin.settings.mcp'))
            ->assertOk()
            ->assertSee('data-management-missing', false)
            ->assertSee('href="'.route('admin.telegram.settings').'"', false);
    });

    it('sends Telegram to the new page and the ping to Integrations on Monitoring', function () {
        $this->actingAs(staffUser())->get(route('admin.monitoring'))
            ->assertOk()
            ->assertSee('href="'.route('admin.telegram.settings').'"', false)
            ->assertSee('href="'.route('admin.settings.integrations').'"', false)
            ->assertSee(__('monitoring.cards.set_up_telegram'))
            ->assertSee(__('monitoring.cards.set_up'));
    });

    it('links the bot page fix hints to the new page with the permission only', function () {
        $this->actingAs(staffUser())->get(route('admin.telegram.bot'))
            ->assertOk()
            ->assertSee('data-settings-link', false)
            ->assertSee('href="'.route('admin.telegram.settings').'"', false);

        $this->actingAs(telegramOperatorWithoutSettings())->get(route('admin.telegram.bot'))
            ->assertOk()
            ->assertDontSee('data-settings-link', false);
    });

    it('no longer sends people to Integrations for Telegram, in any language', function (string $locale) {
        $texts = [
            'admin.telegram_bot.problem_hints.telegram_token',
            'admin.telegram_bot.checks_fix.token',
            'admin.telegram_bot.checks_fix.webhook_secret',
            'admin.telegram_bot.checks_fix.chat',
            'admin.telegram_bot.errors.no_secret',
            'admin.telegram_bot.errors.no_token',
            'mcp.management_missing',
            'mcp.integrations_link',
            'mcp.errors.management_chat_missing',
            'crm.dashboard.telegram_failures.settings',
            'monitoring.cards.set_up_telegram',
        ];

        foreach ($texts as $key) {
            expect(trans($key, [], $locale))->not->toBe($key)->not->toMatch('/integrat|интеграц/iu');
        }

        foreach (['title', 'subtitle', 'audit_subtitle', 'open'] as $key) {
            expect(trans('admin.telegram_settings.'.$key, [], $locale))->not->toBe('admin.telegram_settings.'.$key);
        }
    })->with(['uz', 'ru', 'en']);
});
