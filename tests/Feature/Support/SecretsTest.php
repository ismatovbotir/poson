<?php

use App\Enums\IntegrationSource;
use App\Models\IntegrationSecret;
use App\Models\Setting;
use App\Support\CompanySettings;
use App\Support\Integrations\IntegrationRegistry;
use App\Support\Secrets;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

const FAKE_ANTHROPIC_KEY = 'sk-ant-fake-0000000000000000000000000000wxyz';

beforeEach(fn () => freshSecrets());

describe('precedence', function () {
    it('uses the registry default when nothing is set', function () {
        expect(Secrets::source('payme.mode'))->toBe(IntegrationSource::Default)
            ->and(Secrets::get('payme.mode'))->toBe('test')
            ->and(config('services.payme.mode'))->toBe('test')
            ->and(config('ai-assist.limits.daily_text'))->toBe(100)
            ->and(config('ai-assist.limits.daily_image'))->toBe(10)
            ->and(config('ai-assist.enabled'))->toBeFalse()
            ->and(Secrets::source('anthropic.api_key'))->toBe(IntegrationSource::None)
            ->and(Secrets::isConfigured('anthropic.api_key'))->toBeFalse()
            ->and(config('services.anthropic.key'))->toBeNull();
    });

    it('prefers the admin value over the default', function () {
        IntegrationSecret::factory()->withValue('anthropic.api_key', FAKE_ANTHROPIC_KEY)->create();
        Setting::factory()->create(['key' => 'payme.mode', 'group' => 'integrations', 'value' => 'prod']);
        Setting::factory()->create(['key' => 'ai.daily_text_limit', 'group' => 'integrations', 'value' => 40]);
        Secrets::flush();

        expect(Secrets::source('anthropic.api_key'))->toBe(IntegrationSource::Db)
            ->and(Secrets::get('anthropic.api_key'))->toBe(FAKE_ANTHROPIC_KEY)
            ->and(config('services.anthropic.key'))->toBe(FAKE_ANTHROPIC_KEY)
            ->and(Secrets::isConfigured('anthropic.api_key'))->toBeTrue()
            ->and(config('services.payme.mode'))->toBe('prod')
            ->and(config('ai-assist.limits.daily_text'))->toBe(40);
    });

    it('prefers a non-empty .env value over the admin value', function () {
        IntegrationSecret::factory()->withValue('anthropic.api_key', FAKE_ANTHROPIC_KEY)->create();
        Setting::factory()->create(['key' => 'payme.mode', 'group' => 'integrations', 'value' => 'prod']);

        freshSecrets(['services.anthropic.key' => 'env-anthropic-key-000000000000', 'services.payme.mode' => 'test']);

        expect(Secrets::source('anthropic.api_key'))->toBe(IntegrationSource::Env)
            ->and(Secrets::get('anthropic.api_key'))->toBe('env-anthropic-key-000000000000')
            ->and(config('services.anthropic.key'))->toBe('env-anthropic-key-000000000000')
            ->and(Secrets::hint('anthropic.api_key'))->toBeNull()
            ->and(Secrets::source('payme.mode'))->toBe(IntegrationSource::Env)
            ->and(config('services.payme.mode'))->toBe('test');
    });

    it('treats an empty .env value as not set', function () {
        Setting::factory()->create(['key' => 'telegram.chats.sales', 'group' => 'integrations', 'value' => '-1001234567890']);

        freshSecrets(['services.telegram.chats.sales' => '']);

        expect(Secrets::source('telegram.chats.sales'))->toBe(IntegrationSource::Db)
            ->and(config('services.telegram.chats.sales'))->toBe('-1001234567890');
    });

    it('keeps an explicit false from .env', function () {
        Setting::factory()->create(['key' => 'ai.enabled', 'group' => 'integrations', 'value' => true]);

        freshSecrets(['ai-assist.enabled' => false]);

        expect(Secrets::source('ai.enabled'))->toBe(IntegrationSource::Env)
            ->and(config('ai-assist.enabled'))->toBeFalse();
    });

    it('ignores a stored non-secret value that no longer passes its rules', function () {
        Setting::factory()->create(['key' => 'ai.text_model', 'group' => 'integrations', 'value' => 'retired-model']);
        Secrets::flush();

        expect(Secrets::source('ai.text_model'))->toBe(IntegrationSource::Default)
            ->and(config('ai-assist.text_model'))->toBe(config('ai-assist.models.text')[0]);
    });

    it('does not mix integration rows into the company settings', function () {
        Setting::factory()->create(['key' => 'telegram.chats.sales', 'group' => 'integrations', 'value' => '-1001234567890']);

        expect(app(CompanySettings::class)->all())->not->toHaveKey('telegram.chats.sales');
    });
});

describe('secrets at rest', function () {
    it('stores the value encrypted and caches only the ciphertext', function () {
        IntegrationSecret::factory()->withValue('anthropic.api_key', FAKE_ANTHROPIC_KEY)->create();
        Secrets::flush();
        Secrets::get('anthropic.api_key');

        $raw = DB::table('integration_secrets')->value('value');

        expect($raw)->not->toContain(FAKE_ANTHROPIC_KEY)
            ->and(serialize(Cache::get(Secrets::CACHE_KEY)))->not->toContain(FAKE_ANTHROPIC_KEY)
            ->and(Cache::get(Secrets::CACHE_KEY)['secrets']['anthropic.api_key']['value'])->toBe($raw)
            ->and(IntegrationSecret::query()->first()->toArray())->not->toHaveKey('value');
    });

    it('counts a value that can no longer be decrypted as not configured and reports it without the value', function () {
        Exceptions::fake();

        DB::table('integration_secrets')->insert([
            'key' => 'telegram.bot_token',
            'value' => 'eyJpdiI6ImJyb2tlbiIsInZhbHVlIjoiYnJva2VuIiwibWFjIjoiYnJva2VuIn0=',
            'hint' => 'wxyz',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Secrets::flush();

        expect(Secrets::isConfigured('telegram.bot_token'))->toBeFalse()
            ->and(Secrets::source('telegram.bot_token'))->toBe(IntegrationSource::None)
            ->and(Secrets::isUnreadable('telegram.bot_token'))->toBeTrue()
            ->and(Secrets::hint('telegram.bot_token'))->toBeNull()
            ->and(config('services.telegram.bot_token'))->toBeNull();

        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), '[telegram.bot_token]') && ! str_contains($e->getMessage(), 'eyJpdiI6'));
    });

    it('gives a hint only for values of 20 characters or more', function (string $value, ?string $hint) {
        expect(IntegrationSecret::hintFor($value))->toBe($hint);
    })->with([
        '12 characters' => ['abcdefgh1234', null],
        '19 characters' => ['abcdefghijklmno1234', null],
        '20 characters' => ['abcdefghijklmnop1234', '1234'],
        'long' => [FAKE_ANTHROPIC_KEY, 'wxyz'],
    ]);

    it('exposes the hint of an admin secret', function () {
        IntegrationSecret::factory()->withValue('anthropic.api_key', FAKE_ANTHROPIC_KEY)->create();
        Secrets::flush();

        expect(Secrets::hint('anthropic.api_key'))->toBe('wxyz');
    });
});

describe('queue workers', function () {
    it('re-applies before the next job when another process changed a value', function () {
        expect(config('services.payme.mode'))->toBe('test');

        // Another process saves "prod" and publishes a new version.
        DB::table('settings')->insert(['key' => 'payme.mode', 'group' => 'integrations', 'value' => json_encode('prod'), 'created_at' => now(), 'updated_at' => now()]);
        Cache::forget(Secrets::CACHE_KEY);
        Cache::forever(Secrets::VERSION_KEY, 'published-by-web');

        expect(config('services.payme.mode'))->toBe('test');

        event(new JobProcessing('database', Mockery::mock(Job::class)->shouldIgnoreMissing([])));

        expect(config('services.payme.mode'))->toBe('prod')
            ->and(Secrets::version())->toBe('published-by-web');
    });

    it('does not reload before a job when nothing changed', function () {
        $version = Secrets::version();

        DB::table('settings')->insert(['key' => 'payme.mode', 'group' => 'integrations', 'value' => json_encode('prod'), 'created_at' => now(), 'updated_at' => now()]);

        event(new JobProcessing('database', Mockery::mock(Job::class)->shouldIgnoreMissing([])));

        expect(Secrets::version())->toBe($version)
            ->and(config('services.payme.mode'))->toBe('test');
    });
});

it('ignores a .env value of a select field outside its options and reports the key only', function () {
    Exceptions::fake();
    Setting::factory()->create(['key' => 'payme.mode', 'group' => 'integrations', 'value' => 'prod']);

    freshSecrets(['services.payme.mode' => 'live-SECRETISH', 'ai-assist.text_model' => 'unknown-model']);

    expect(Secrets::source('payme.mode'))->toBe(IntegrationSource::Db)
        ->and(config('services.payme.mode'))->toBe('prod')
        ->and(Secrets::source('ai.text_model'))->toBe(IntegrationSource::Default);

    Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), '[payme.mode]') && ! str_contains($e->getMessage(), 'SECRETISH'));
    Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), '[ai.text_model]'));
});

it('declares every integration field in config without a default and in .env.example', function () {
    $example = file_get_contents(base_path('.env.example'));
    $services = file_get_contents(config_path('services.php'));
    $aiAssist = file_get_contents(config_path('ai-assist.php'));

    foreach (IntegrationRegistry::fields() as $field) {
        expect($example)->toMatch('/^'.$field->env.'=$/m');
        expect($services.$aiAssist)->toContain("env('{$field->env}')");
    }
});
