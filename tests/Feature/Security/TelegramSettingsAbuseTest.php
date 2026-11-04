<?php

use App\Livewire\Admin\Settings\Integrations;
use App\Livewire\Admin\Telegram\Settings;
use App\Models\IntegrationSecret;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\Admin\PasswordConfirmation;
use App\Support\Secrets;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLifecycleHooks\DirectlyCallingLifecycleHooksNotAllowedException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Plan 21 stream C abuse cases for Telegram → Settings and Settings →
 * Integrations (trait ManagesIntegrationSettings). Happy paths and scope live
 * in tests/Feature/Admin/Telegram/SettingsScreenTest.php; here only hostile
 * angles. Bot API faked, fake tokens only, test DB only.
 */
const TSA_TOKEN = '123456789:AAAbuseTokenAbuseTokenAbuseTokenAb9876';

const TSA_PAYME_KEY = 'fake-payme-key-1111111';

function tsetScreen(string $class, ?User $user = null): Testable
{
    return Livewire::actingAs($user ?? staffUser())->test($class);
}

/**
 * The HTML, snapshot and effects the browser would receive.
 */
function tsetPayload(Testable $component): string
{
    return $component->html().json_encode($component->snapshot).json_encode($component->effects);
}

/**
 * @return array<string, mixed>
 */
function tsetStateOf(): array
{
    return [
        'secrets' => IntegrationSecret::query()->orderBy('key')->get()->map->getAttributes()->all(),
        'settings' => Setting::query()->orderBy('key')->get()->map->getAttributes()->all(),
        'audits' => SettingsAudit::query()->count(),
    ];
}

/**
 * GET the page, return the snapshot of the component whose data has $dataKey.
 */
function tsetSnapshot(string $url, string $dataKey): string
{
    $html = test()->get($url)->assertOk()->getContent();

    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

    foreach ($matches[1] as $raw) {
        $snapshot = html_entity_decode($raw, ENT_QUOTES);

        if (str_contains($snapshot, '"'.$dataKey.'"')) {
            return $snapshot;
        }
    }

    throw new RuntimeException("No snapshot with [{$dataKey}] on {$url}.");
}

/**
 * One raw Livewire update request with a single call.
 *
 * @param  list<mixed>  $params
 */
function tsetUpdateRequest(string $snapshot, string $method, array $params = []): TestResponse
{
    return test()->withHeaders(['X-Livewire' => '1'])
        ->postJson(app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'calls' => [['method' => $method, 'params' => $params, 'metadata' => []]],
                'updates' => [],
            ]],
        ]);
}

const TSA_SCREENS = ['integrations' => [Integrations::class], 'telegram page' => [Settings::class]];

beforeEach(function () {
    freshSecrets();
});

describe('lifecycle hooks', function () {
    it('refuses a direct call of a lifecycle hook and keeps form and secrets', function (string $class, string $hook) {
        IntegrationSecret::factory()->withValue('telegram.bot_token', TSA_TOKEN)->create();
        IntegrationSecret::factory()->withValue('payme.key', TSA_PAYME_KEY)->create();
        Secrets::flush();
        confirmAdminPassword();
        $before = tsetStateOf();

        $component = tsetScreen($class);
        $values = $component->get('form.values');

        expect(fn () => $component->call($hook))->toThrow(DirectlyCallingLifecycleHooksNotAllowedException::class);

        expect($component->get('form.values'))->toBe($values)
            ->and(tsetStateOf())->toBe($before)
            ->and(Secrets::get('telegram.bot_token'))->toBe(TSA_TOKEN)
            ->and(tsetPayload($component))->not->toContain('AAAbuseToken')->not->toContain(TSA_PAYME_KEY);
    })->with(TSA_SCREENS)->with(['mount', 'boot', 'dehydrate', 'hydrate']);
});

describe('test results lock', function () {
    it('refuses every forged shape and renders none of it', function (string $class, mixed $forged) {
        $component = tsetScreen($class);

        expect(fn () => $component->set('testResults', $forged))->toThrow(CannotUpdateLockedPropertyException::class);

        $component->assertSet('testResults', []);
        expect($component->html())->not->toContain('FORGED-MARKER');
    })->with(TSA_SCREENS)->with([
        'a string' => 'FORGED-MARKER',
        'no ok' => [['telegram_bot_token' => ['message' => 'FORGED-MARKER']]],
        'array message' => [['telegram_bot_token' => ['ok' => true, 'message' => ['FORGED-MARKER']]]],
        'html message' => [['telegram_bot_token' => ['ok' => true, 'message' => '<script>FORGED-MARKER</script>']]],
    ]);

    it('refuses a forged nested key', function (string $class, string $path) {
        $component = tsetScreen($class);

        expect(fn () => $component->set($path, 'FORGED-MARKER'))->toThrow(CannotUpdateLockedPropertyException::class);

        expect($component->html())->not->toContain('FORGED-MARKER');
    })->with(TSA_SCREENS)->with(['ok' => 'testResults.telegram_bot_token.ok', 'message' => 'testResults.uzqr_connection.message']);

    it('refuses a direct call of forgetTestResults', function (string $class) {
        expect(fn () => tsetScreen($class)->call('forgetTestResults', 'telegram'))->toThrow('Public method [forgetTestResults] not found');
    })->with(TSA_SCREENS);
});

describe('cross-screen writes', function () {
    it('writes nothing for payment values sent to the Telegram page', function () {
        confirmAdminPassword();

        tsetScreen(Settings::class)
            ->set('form.values.payme.mode', 'prod')
            ->set('form.values.uzqr.mode', 'prod')
            ->call('save', 'telegram')
            ->assertNotDispatched('confirm');

        expect(Setting::query()->where('key', 'like', 'payme.%')->orWhere('key', 'like', 'uzqr.%')->count())->toBe(0)
            ->and(SettingsAudit::query()->count())->toBe(0);
    });

    it('writes no Telegram row when Telegram values are sent to another section', function (string $group) {
        confirmAdminPassword();

        tsetScreen(Integrations::class)
            ->set('form.values.telegram.chats.sales', '-1009999999999')
            ->call('save', $group);

        expect(Setting::query()->where('key', 'like', 'telegram.%')->count())->toBe(0)
            ->and(SettingsAudit::query()->where('key', 'like', 'telegram.%')->count())->toBe(0);
    })->with(['uzqr', 'monitoring']);

    it('stores no foreign input when another secret is replaced', function () {
        confirmAdminPassword();

        $component = tsetScreen(Integrations::class)->update(
            calls: [['method' => 'replaceSecret', 'params' => ['uzqr.api_key'], 'path' => '']],
            updates: ['secretInputs.telegram.bot_token' => TSA_TOKEN, 'secretInputs.uzqr.api_key' => TSA_PAYME_KEY],
        );

        expect(Secrets::get('uzqr.api_key'))->toBe(TSA_PAYME_KEY)
            ->and(Secrets::isConfigured('telegram.bot_token'))->toBeFalse()
            ->and(IntegrationSecret::query()->pluck('key')->all())->toBe(['uzqr.api_key'])
            ->and(SettingsAudit::query()->pluck('key')->all())->toBe(['uzqr.api_key'])
            ->and(tsetPayload($component))->not->toContain('AAAbuseToken')->not->toContain(TSA_PAYME_KEY);
    });

    it('does not fall back to another secret input when its own is empty', function () {
        confirmAdminPassword();

        tsetScreen(Integrations::class)->update(
            calls: [['method' => 'replaceSecret', 'params' => ['uzqr.api_key'], 'path' => '']],
            updates: ['secretInputs.telegram.bot_token' => TSA_TOKEN],
        );

        expect(IntegrationSecret::query()->count())->toBe(0)
            ->and(Secrets::isConfigured('telegram.bot_token'))->toBeFalse();
    });
});

describe('stale tab', function () {
    it('404s a Telegram token typed on Integrations and leaks it nowhere', function () {
        confirmAdminPassword();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.json_encode($event->context);
        });

        $component = tsetScreen(Integrations::class)->update(
            calls: [['method' => 'replaceSecret', 'params' => ['telegram.bot_token'], 'path' => '']],
            updates: ['secretInputs.telegram.bot_token' => TSA_TOKEN],
        )->assertNotFound();

        expect(IntegrationSecret::query()->count())->toBe(0)
            ->and(SettingsAudit::query()->count())->toBe(0)
            ->and(implode("\n", $logged))->not->toContain('AAAbuseToken')
            ->and(tsetPayload($component))->not->toContain('AAAbuseToken');
    });
});

describe('password confirmation', function () {
    it('is one window for both screens', function () {
        confirmAdminPassword();
        $user = staffUser();

        tsetScreen(Integrations::class, $user)->call('save', 'uzqr')->assertNoRedirect();
        tsetScreen(Settings::class, $user)->call('save', 'telegram')->assertNoRedirect();
    });

    it('blocks actions on both screens once the window expired', function (string $class, string $method, array $params) {
        confirmAdminPassword(PasswordConfirmation::timeout() + 1);

        tsetScreen($class)->call($method, ...$params)->assertRedirect(route('admin.confirm-password'));

        expect(tsetStateOf()['audits'])->toBe(0)
            ->and(IntegrationSecret::query()->count())->toBe(0);
    })->with([
        'integrations save' => [Integrations::class, 'save', ['uzqr']],
        'integrations replace' => [Integrations::class, 'replaceSecret', ['uzqr.api_key']],
        'integrations test' => [Integrations::class, 'test', ['uzqr']],
        'telegram save' => [Settings::class, 'save', ['telegram']],
        'telegram replace' => [Settings::class, 'replaceSecret', ['telegram.bot_token']],
        'telegram test' => [Settings::class, 'test', ['telegram']],
        'telegram generate' => [Settings::class, 'generateWebhookSecret', []],
    ]);

    it('keeps url.intended on the server route despite a hostile Referer or query', function (string $class, string $route) {
        $component = Livewire::actingAs(staffUser())
            ->withHeaders(['Referer' => 'https://evil.test/phish'])
            ->withQueryParams(['return' => 'https://evil.test', 'redirect' => 'https://evil.test'])
            ->test($class)
            ->call('confirmPassword')
            ->assertRedirect(route('admin.confirm-password'));

        expect(session('url.intended'))->toBe(route($route))
            ->and(json_encode($component->effects))->not->toContain('evil.test');
    })->with([
        'integrations' => [Integrations::class, 'admin.settings.integrations'],
        'telegram page' => [Settings::class, 'admin.telegram.settings'],
    ]);

    it('keeps url.intended on the server route in a real request with a hostile Referer', function () {
        $user = staffUser();
        $this->actingAs($user)
            ->withHeaders(['Referer' => 'https://evil.test/phish'])
            ->get(route('admin.telegram.settings').'?return=https://evil.test')
            ->assertOk();

        $snapshot = tsetSnapshot(route('admin.telegram.settings'), 'secretInputs');

        tsetUpdateRequest($snapshot, 'confirmPassword')->assertOk();

        expect(session('url.intended'))->toBe(route('admin.telegram.settings'));
    });
});

describe('test rate limit', function () {
    it('is shared per user across screens', function () {
        Http::fake();
        $user = staffUser();
        confirmAdminPassword();
        IntegrationSecret::factory()->withValue('telegram.bot_token', TSA_TOKEN)->create();
        Setting::factory()->create(['key' => 'telegram.chats.sales', 'group' => 'integrations', 'value' => '-100123']);
        Secrets::flush();

        $integrations = tsetScreen(Integrations::class, $user);

        foreach (range(1, 5) as $ignored) {
            $integrations->call('test', 'uzqr');
        }

        Http::fake();

        $page = tsetScreen(Settings::class, $user)->call('test', 'telegram_chat', 'sales');

        expect($page->get('testResults.telegram_chats_sales.ok'))->toBeFalse()
            ->and($page->get('testResults.telegram_chats_sales.message'))->toBeString()
            ->and(telegramApiCalls())->toBe([]);
    });
});

describe('env lock', function () {
    it('deletes nothing and keeps the env value when clearing a locked webhook secret', function () {
        telegramBotConfigured();
        IntegrationSecret::factory()->withValue('telegram.webhook_secret', str_repeat('Stored', 8))->create();
        confirmAdminPassword();

        tsetScreen(Settings::class)->call('clearSecret', 'telegram.webhook_secret');

        expect(IntegrationSecret::query()->where('key', 'telegram.webhook_secret')->count())->toBe(1)
            ->and(SettingsAudit::query()->count())->toBe(0)
            ->and(Secrets::get('telegram.webhook_secret'))->toBe(TG_WEBHOOK_SECRET);
    });
});

describe('deactivated admin with an open snapshot', function () {
    it('is refused on a real update request', function (string $method, array $params) {
        telegramBotConfigured(['services.telegram.webhook_secret' => null]);
        Http::fake();
        IntegrationSecret::factory()->withValue('telegram.bot_token', TSA_TOKEN)->create();
        IntegrationSecret::factory()->withValue('telegram.webhook_secret', str_repeat('Stored', 8))->create();
        Secrets::flush();

        $user = staffUser();
        $this->actingAs($user);
        $snapshot = tsetSnapshot(route('admin.telegram.settings'), 'secretInputs');
        $before = tsetStateOf();

        $user->forceFill(['is_active' => false])->save();

        $this->withSession([PasswordConfirmation::SESSION_KEY => now()->unix()]);

        tsetUpdateRequest($snapshot, $method, $params)->assertForbidden();

        $this->assertGuest();
        expect(tsetStateOf())->toBe($before);
        Http::assertNothingSent();
    })->with([
        'generate' => ['generateWebhookSecret', []],
        'clear' => ['clearSecret', ['telegram.bot_token']],
    ]);
});

describe('redirect markup', function () {
    it('matches only the literal #section-telegram', function () {
        $html = $this->actingAs(staffUser())->get(route('admin.settings.integrations'))->assertOk()->getContent();

        preg_match_all("/location\.hash === '([^']*)'/", $html, $matches);

        expect($matches[1])->toBe(['#section-telegram']);

        foreach (['#section-payme', '#section-telegram2', '#section-telegram ', '#SECTION-TELEGRAM', '#section-telegram/../x', ''] as $hash) {
            expect($hash === $matches[1][0])->toBeFalse();
        }
    });

    it('has no hash redirect on the Telegram page', function () {
        $html = $this->actingAs(staffUser())->get(route('admin.telegram.settings'))->assertOk()->getContent();

        expect($html)->not->toContain('location.hash')->not->toContain('location.replace');
    });
});

describe('stale results after generating', function () {
    it('clears the Telegram results when the webhook secret is generated', function () {
        telegramBotConfigured(['services.telegram.webhook_secret' => null]);
        confirmAdminPassword();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['username' => 'poson_bot']])]);

        tsetScreen(Settings::class)
            ->call('test', 'telegram')
            ->call('test', 'telegram_chat', 'sales')
            ->assertSet('testResults.telegram_bot_token.ok', true)
            ->assertSet('testResults.telegram_chats_sales.ok', true)
            ->call('generateWebhookSecret')
            ->assertSet('testResults', []);
    });

    it('clears only the IndexNow results when the IndexNow key is generated', function () {
        confirmAdminPassword();
        Http::fake();

        $component = tsetScreen(Integrations::class)->call('test', 'uzqr')->call('test', 'indexnow');

        expect($component->get('testResults'))->toHaveKeys(['uzqr_connection', 'indexnow_key']);

        $component->call('generateIndexNowKey');

        expect($component->get('testResults'))->toHaveKey('uzqr_connection')->not->toHaveKey('indexnow_key');
    });
});
