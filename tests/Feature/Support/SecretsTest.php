<?php

use App\Enums\IntegrationSource;
use App\Models\IntegrationSecret;
use App\Models\Setting;
use App\Support\CompanySettings;
use App\Support\Integrations\IntegrationRegistry;
use App\Support\Secrets;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

const FAKE_PAYME_KEY = 'fake-payme-key-00000000000000000000000wxyz';

beforeEach(fn () => freshSecrets());

describe('precedence', function () {
    it('uses the registry default when nothing is set', function () {
        expect(Secrets::source('payme.mode'))->toBe(IntegrationSource::Default)
            ->and(Secrets::get('payme.mode'))->toBe('test')
            ->and(config('services.payme.mode'))->toBe('test')
            ->and(config('services.telegram.locale'))->toBe('uz')
            ->and(config('services.telegram.notify_new_leads'))->toBeTrue()
            ->and(Secrets::source('payme.key'))->toBe(IntegrationSource::None)
            ->and(Secrets::isConfigured('payme.key'))->toBeFalse()
            ->and(config('services.payme.key'))->toBeNull();
    });

    it('prefers the admin value over the default', function () {
        IntegrationSecret::factory()->withValue('payme.key', FAKE_PAYME_KEY)->create();
        Setting::factory()->create(['key' => 'payme.mode', 'group' => 'integrations', 'value' => 'prod']);
        Setting::factory()->create(['key' => 'telegram.locale', 'group' => 'integrations', 'value' => 'ru']);
        Secrets::flush();

        expect(Secrets::source('payme.key'))->toBe(IntegrationSource::Db)
            ->and(Secrets::get('payme.key'))->toBe(FAKE_PAYME_KEY)
            ->and(config('services.payme.key'))->toBe(FAKE_PAYME_KEY)
            ->and(Secrets::isConfigured('payme.key'))->toBeTrue()
            ->and(config('services.payme.mode'))->toBe('prod')
            ->and(config('services.telegram.locale'))->toBe('ru');
    });

    it('prefers a non-empty .env value over the admin value', function () {
        IntegrationSecret::factory()->withValue('payme.key', FAKE_PAYME_KEY)->create();
        Setting::factory()->create(['key' => 'payme.mode', 'group' => 'integrations', 'value' => 'prod']);

        freshSecrets(['services.payme.key' => 'env-payme-key-000000000000', 'services.payme.mode' => 'test']);

        expect(Secrets::source('payme.key'))->toBe(IntegrationSource::Env)
            ->and(Secrets::get('payme.key'))->toBe('env-payme-key-000000000000')
            ->and(config('services.payme.key'))->toBe('env-payme-key-000000000000')
            ->and(Secrets::hint('payme.key'))->toBeNull()
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
        Setting::factory()->create(['key' => 'telegram.notify_new_leads', 'group' => 'integrations', 'value' => true]);

        freshSecrets(['services.telegram.notify_new_leads' => false]);

        expect(Secrets::source('telegram.notify_new_leads'))->toBe(IntegrationSource::Env)
            ->and(config('services.telegram.notify_new_leads'))->toBeFalse();
    });

    it('ignores a stored non-secret value that no longer passes its rules', function () {
        Setting::factory()->create(['key' => 'telegram.locale', 'group' => 'integrations', 'value' => 'retired-locale']);
        Secrets::flush();

        expect(Secrets::source('telegram.locale'))->toBe(IntegrationSource::Default)
            ->and(config('services.telegram.locale'))->toBe('uz');
    });

    it('does not mix integration rows into the company settings', function () {
        Setting::factory()->create(['key' => 'telegram.chats.sales', 'group' => 'integrations', 'value' => '-1001234567890']);

        expect(app(CompanySettings::class)->all())->not->toHaveKey('telegram.chats.sales');
    });
});

describe('secrets at rest', function () {
    it('stores the value encrypted and caches only the ciphertext', function () {
        IntegrationSecret::factory()->withValue('payme.key', FAKE_PAYME_KEY)->create();
        Secrets::flush();
        Secrets::get('payme.key');

        $raw = DB::table('integration_secrets')->value('value');

        expect($raw)->not->toContain(FAKE_PAYME_KEY)
            ->and(serialize(Cache::get(Secrets::CACHE_KEY)))->not->toContain(FAKE_PAYME_KEY)
            ->and(Cache::get(Secrets::CACHE_KEY)['secrets']['payme.key']['value'])->toBe($raw)
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
        'long' => [FAKE_PAYME_KEY, 'wxyz'],
    ]);

    it('exposes the hint of an admin secret', function () {
        IntegrationSecret::factory()->withValue('payme.key', FAKE_PAYME_KEY)->create();
        Secrets::flush();

        expect(Secrets::hint('payme.key'))->toBe('wxyz');
    });

    it('never applies, lists or decrypts an orphan secret of an unregistered key (e.g. a removed AI key)', function () {
        IntegrationSecret::factory()->withValue('anthropic.api_key', 'sk-ant-orphan-0000000000000000000000000000')->create();
        config(['services.anthropic' => null]);
        Secrets::flush();

        $cached = Cache::get(Secrets::CACHE_KEY);

        expect(IntegrationRegistry::has('anthropic.api_key'))->toBeFalse()
            ->and($cached['secrets'])->not->toHaveKey('anthropic.api_key')
            ->and(serialize($cached))->not->toContain('sk-ant-orphan')
            ->and(config('services.anthropic'))->toBeNull()
            ->and(collect(config()->all())->flatten()->containsStrict('sk-ant-orphan-0000000000000000000000000000'))->toBeFalse()
            ->and(fn () => Secrets::get('anthropic.api_key'))->toThrow(InvalidArgumentException::class);
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

    freshSecrets(['services.payme.mode' => 'live-SECRETISH', 'services.telegram.locale' => 'klingon']);

    expect(Secrets::source('payme.mode'))->toBe(IntegrationSource::Db)
        ->and(config('services.payme.mode'))->toBe('prod')
        ->and(Secrets::source('telegram.locale'))->toBe(IntegrationSource::Default);

    Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), '[payme.mode]') && ! str_contains($e->getMessage(), 'SECRETISH'));
    Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), '[telegram.locale]'));
});

describe('invalid .env values', function () {
    it('reports an invalid .env value once an hour across fresh instances, by key and env name only', function () {
        Exceptions::fake();

        freshSecrets(['services.payme.mode' => 'live-SECRETISH']);
        Secrets::get('payme.mode');
        freshSecrets(['services.payme.mode' => 'live-SECRETISH']);
        Secrets::get('payme.mode');

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), '[payme.mode]')
            && str_contains($e->getMessage(), IntegrationRegistry::field('payme.mode')->env)
            && ! str_contains($e->getMessage(), 'SECRETISH'));
    });

    it('reports again for another key and after the hour', function () {
        Exceptions::fake();

        freshSecrets(['services.payme.mode' => 'bad', 'services.telegram.locale' => 'klingon']);
        Secrets::get('payme.mode');
        Secrets::get('telegram.locale');

        Exceptions::assertReportedCount(2);

        $this->travel(61)->minutes();
        freshSecrets(['services.payme.mode' => 'bad']);
        Secrets::get('payme.mode');

        Exceptions::assertReportedCount(3);
    });

    it('still reports and does not break when the cache store fails', function () {
        Exceptions::fake();
        $GLOBALS['secrets_test_add_attempts'] = [];
        Cache::extend('failing-add', fn () => Cache::repository(new class extends ArrayStore
        {
            public function add($key, $value, $seconds): bool
            {
                $GLOBALS['secrets_test_add_attempts'][] = $key;

                throw new RuntimeException('cache down');
            }
        }));
        config(['cache.stores.failing-add' => ['driver' => 'failing-add'], 'cache.default' => 'failing-add']);

        freshSecrets(['services.payme.mode' => 'live-SECRETISH']);

        expect(Secrets::get('payme.mode'))->toBe('test')
            ->and($GLOBALS['secrets_test_add_attempts'])->toContain('integration_invalid_env:payme.mode');
        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), '[payme.mode]'));
    });
});

describe('invalidEnv', function () {
    it('names bad bool, bad select and malformed token by key and env name only', function () {
        freshSecrets([
            'services.telegram.notify_inbox' => 'MARKER-maybe',
            'services.telegram.locale' => 'MARKER-klingon',
            'services.telegram.bot_token' => 'MARKER-not-a-token',
            'services.telegram.chats.sales' => '-1001234567890',
            'services.telegram.chats.inbox' => 'MARKER-chat',
        ]);

        $result = Secrets::invalidEnv('telegram');

        expect($result)->toBe([
            ['key' => 'telegram.bot_token', 'env' => 'TELEGRAM_BOT_TOKEN', 'reason' => 'format'],
            ['key' => 'telegram.chats.inbox', 'env' => 'TELEGRAM_INBOX_CHAT_ID', 'reason' => 'format'],
            ['key' => 'telegram.notify_inbox', 'env' => 'TELEGRAM_NOTIFY_INBOX', 'reason' => 'ignored'],
            ['key' => 'telegram.locale', 'env' => 'TELEGRAM_LOCALE', 'reason' => 'ignored'],
        ])
            ->and(json_encode($result))->not->toContain('MARKER')
            ->and(array_map('array_keys', $result))->each->toBe(['key', 'env', 'reason']);
    });

    it('is empty for valid, empty and other-group values', function () {
        freshSecrets(['services.telegram.chats.sales' => '-1001234567890', 'services.telegram.locale' => 'ru', 'services.payme.mode' => 'MARKER-bad']);

        expect(Secrets::invalidEnv('telegram'))->toBe([]);
    });

    it('does not log or report anything itself and keeps the marker out of the log', function () {
        Exceptions::fake();
        freshSecrets(['services.telegram.bot_token' => 'MARKER-not-a-token']);

        Secrets::invalidEnv('telegram');

        Exceptions::assertReportedCount(0);
    });
});

it('declares every integration field in config without a default and in .env.example', function () {
    $example = file_get_contents(base_path('.env.example'));
    $services = file_get_contents(config_path('services.php'));

    foreach (IntegrationRegistry::fields() as $field) {
        expect($example)->toMatch('/^'.$field->env.'=$/m');
        expect($services)->toContain("env('{$field->env}')");
    }
});

it('declares no AI provider or remove.bg keys any more', function () {
    $example = file_get_contents(base_path('.env.example'));

    expect($example)->not->toMatch('/^(AI_[A-Z_]+|ANTHROPIC_API_KEY|OPENAI_API_KEY|OPENROUTER_API_KEY|REMOVEBG_API_KEY)=/m')
        ->and(config('services'))->not->toHaveKeys(['anthropic', 'openai', 'openrouter', 'removebg'])
        ->and(IntegrationRegistry::GROUPS)->toBe(['telegram', 'payme', 'click', 'uzqr', 'monitoring', 'indexnow']);
});
