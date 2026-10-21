<?php

use App\Actions\Settings\ClearSecret;
use App\Actions\Settings\GenerateWebhookSecret;
use App\Actions\Settings\SaveIntegrationSettings;
use App\Actions\Settings\SetSecret;
use App\Enums\AuditAction;
use App\Enums\IntegrationSource;
use App\Enums\SettingsArea;
use App\Models\IntegrationSecret;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Support\Secrets;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Validation\ValidationException;

const FAKE_BOT_TOKEN = '123456789:AAFakeFakeFakeFakeFakeFakeFakeFake1234';

beforeEach(fn () => freshSecrets());

describe('SetSecret', function () {
    it('stores the secret encrypted, with its hint, applies it and audits without the value', function () {
        $user = staffUser();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.json_encode($event->context);
        });
        Exceptions::fake();

        app(SetSecret::class)->handle('telegram.bot_token', '  '.FAKE_BOT_TOKEN.' ', $user, '10.0.0.5');

        $secret = IntegrationSecret::query()->sole();
        $audit = SettingsAudit::query()->sole();

        expect($secret->key)->toBe('telegram.bot_token')
            ->and($secret->value)->toBe(FAKE_BOT_TOKEN)
            ->and($secret->hint)->toBe('1234')
            ->and($secret->updated_by)->toBe($user->id)
            ->and(Secrets::get('telegram.bot_token'))->toBe(FAKE_BOT_TOKEN)
            ->and(config('services.telegram.bot_token'))->toBe(FAKE_BOT_TOKEN)
            ->and($audit->area)->toBe(SettingsArea::Integrations)
            ->and($audit->key)->toBe('telegram.bot_token')
            ->and($audit->action)->toBe(AuditAction::Updated)
            ->and($audit->ip)->toBe('10.0.0.5')
            ->and(json_encode($audit->getAttributes()))->not->toContain('AAFake');

        expect(implode("\n", $logged))->not->toContain('AAFake');
        Exceptions::assertNothingReported();
    });

    it('rejects a malformed value without echoing it', function () {
        try {
            app(SetSecret::class)->handle('telegram.bot_token', 'not-a-token-SECRETPART', staffUser(), null);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('value')
                ->and(json_encode($e->errors()))->not->toContain('SECRETPART');
        }

        expect(IntegrationSecret::query()->count())->toBe(0)
            ->and(SettingsAudit::query()->count())->toBe(0);
    });

    it('refuses non-secret and unknown keys', function (string $key) {
        app(SetSecret::class)->handle($key, 'whatever-value-123', null, null);
    })->with(['payme.mode', 'unknown.key'])->throws(InvalidArgumentException::class);

    it('stores no hint for a short value', function () {
        app(SetSecret::class)->handle('click.secret_key', 'Short123', null, null);

        expect(IntegrationSecret::query()->sole()->hint)->toBeNull()
            ->and(Secrets::hint('click.secret_key'))->toBeNull()
            ->and(Secrets::isConfigured('click.secret_key'))->toBeTrue();
    });
});

describe('ClearSecret', function () {
    it('deletes the admin value and audits it', function () {
        IntegrationSecret::factory()->withValue('payme.key', 'payme-fake-key-0000')->create();
        Secrets::flush();

        expect(app(ClearSecret::class)->handle('payme.key', staffUser(), '127.0.0.1'))->toBeTrue();

        expect(IntegrationSecret::query()->count())->toBe(0)
            ->and(Secrets::source('payme.key'))->toBe(IntegrationSource::None)
            ->and(config('services.payme.key'))->toBeNull()
            ->and(SettingsAudit::query()->sole()->action)->toBe(AuditAction::Cleared);
    });

    it('does nothing when no value is saved', function () {
        expect(app(ClearSecret::class)->handle('payme.key', staffUser(), null))->toBeFalse()
            ->and(SettingsAudit::query()->count())->toBe(0);
    });
});

describe('GenerateWebhookSecret', function () {
    it('generates a 48-character alphanumeric secret and audits it as generated', function () {
        app(GenerateWebhookSecret::class)->handle(staffUser(), null);

        $value = Secrets::get('telegram.webhook_secret');

        expect($value)->toMatch('/^[A-Za-z0-9]{48}$/')
            ->and(config('services.telegram.webhook_secret'))->toBe($value)
            ->and(SettingsAudit::query()->sole()->action)->toBe(AuditAction::Generated);

        app(GenerateWebhookSecret::class)->handle(null, null);

        expect(Secrets::get('telegram.webhook_secret'))->not->toBe($value);
    });
});

describe('SaveIntegrationSettings', function () {
    it('saves only changed values, audits each and applies them', function () {
        $changed = app(SaveIntegrationSettings::class)->handle([
            'telegram.chats.sales' => ' -1001234567890 ',
            'telegram.chats.service' => '@poson_service',
            'telegram.chats.finance' => '',
        ], staffUser(), null);

        expect($changed)->toBe(['telegram.chats.sales', 'telegram.chats.service'])
            ->and(config('services.telegram.chats.sales'))->toBe('-1001234567890')
            ->and(config('services.telegram.chats.service'))->toBe('@poson_service')
            ->and(Setting::query()->where('key', 'telegram.chats.sales')->value('group'))->toBe('integrations')
            ->and(SettingsAudit::query()->pluck('key')->all())->toBe(['telegram.chats.sales', 'telegram.chats.service']);

        expect(app(SaveIntegrationSettings::class)->handle(['telegram.chats.sales' => '-1001234567890'], null, null))->toBe([]);
    });

    it('casts numbers and booleans and deletes a cleared value', function () {
        app(SaveIntegrationSettings::class)->handle(['ai.enabled' => true, 'ai.daily_text_limit' => '50', 'ai.monthly_budget_usd' => '25'], null, null);

        expect(config('ai-assist.enabled'))->toBeTrue()
            ->and(config('ai-assist.limits.daily_text'))->toBe(50)
            ->and(config('ai-assist.limits.monthly_budget_usd'))->toBe(25);

        app(SaveIntegrationSettings::class)->handle(['ai.daily_text_limit' => ''], null, null);

        expect(Setting::query()->where('key', 'ai.daily_text_limit')->exists())->toBeFalse()
            ->and(config('ai-assist.limits.daily_text'))->toBe(100)
            ->and(SettingsAudit::query()->latest('id')->first()->action)->toBe(AuditAction::Cleared);
    });

    it('validates with the registry rules', function (string $key, mixed $value) {
        try {
            app(SaveIntegrationSettings::class)->handle([$key => $value], null, null);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey($key);
        }

        expect(Setting::query()->count())->toBe(0);
    })->with([
        'chat id' => ['telegram.chats.sales', 'not a chat'],
        'model outside the allow-list' => ['ai.text_model', 'some-other-model'],
        'negative limit' => ['ai.daily_image_limit', '-1'],
        'payme mode' => ['payme.mode', 'live'],
        'click id' => ['click.service_id', 'abc'],
    ]);

    it('ignores keys locked by .env', function () {
        freshSecrets(['services.telegram.chats.sales' => '-100777']);

        $changed = app(SaveIntegrationSettings::class)->handle(['telegram.chats.sales' => '-100888', 'telegram.chats.service' => '-100999'], null, null);

        expect($changed)->toBe(['telegram.chats.service'])
            ->and(Setting::query()->where('key', 'telegram.chats.sales')->exists())->toBeFalse()
            ->and(config('services.telegram.chats.sales'))->toBe('-100777')
            ->and(SettingsAudit::query()->pluck('key')->all())->toBe(['telegram.chats.service']);
    });

    it('validates the Payme merchant ID and stores it lowercased', function (string $value, bool $valid) {
        try {
            app(SaveIntegrationSettings::class)->handle(['payme.merchant_id' => $value], null, null);
        } catch (ValidationException $e) {
            expect($valid)->toBeFalse()->and($e->errors())->toHaveKey('payme.merchant_id');

            return;
        }

        expect($valid)->toBeTrue()
            ->and(Setting::query()->where('key', 'payme.merchant_id')->value('value'))->toBe(strtolower(trim($value)));
    })->with([
        'valid example' => ['587f72c72cac0d162c722ae2', true],
        'uppercase' => ['587F72C72CAC0D162C722AE2', true],
        '23 characters' => ['587f72c72cac0d162c722ae', false],
        '25 characters' => ['587f72c72cac0d162c722ae2a', false],
        'non-hex character' => ['587f72c72cac0d162c722aeg', false],
    ]);

    it('allows Payme prod only with the merchant ID and the live key', function () {
        try {
            app(SaveIntegrationSettings::class)->handle(['payme.mode' => 'prod'], null, null);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('payme.mode');
        }

        IntegrationSecret::factory()->withValue('payme.key', 'payme-fake-live-key-0000')->create();
        Secrets::flush();

        // The merchant ID may come in the same save.
        expect(app(SaveIntegrationSettings::class)->handle(['payme.merchant_id' => '587f72c72cac0d162c722ae2', 'payme.mode' => 'prod'], null, null))
            ->toBe(['payme.merchant_id', 'payme.mode'])
            ->and(config('services.payme.mode'))->toBe('prod');
    });

    it('refuses secrets and unknown keys', function (string $key) {
        app(SaveIntegrationSettings::class)->handle([$key => 'x'], null, null);
    })->with(['telegram.bot_token', 'unknown.key'])->throws(InvalidArgumentException::class);
});
