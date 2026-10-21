<?php

use App\Actions\Settings\ReencryptSecrets;
use App\Models\IntegrationSecret;
use App\Support\Secrets;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(WithConsoleEvents::class);

const CACHE_TEST_SECRET = 'sk-ant-fake-CONFIGCACHE-0000000000000000';

beforeEach(function () {
    freshSecrets();
    IntegrationSecret::factory()->withValue('anthropic.api_key', CACHE_TEST_SECRET)->create();
    Secrets::flush();
});

afterEach(function () {
    @unlink(app()->getCachedConfigPath());
    Secrets::$suspended = false;
});

describe('config cache', function () {
    it('suspends the overlay while config:cache runs and never writes DB values to the cache file', function (array $parameters) {
        $suspendedDuringCommand = null;
        Event::listen(CommandStarting::class, function () use (&$suspendedDuringCommand): void {
            $suspendedDuringCommand = Secrets::$suspended;
        });

        expect(config('services.anthropic.key'))->toBe(CACHE_TEST_SECRET);

        Artisan::call('config:cache', $parameters);

        $cached = file_get_contents(app()->getCachedConfigPath());

        expect($suspendedDuringCommand)->toBeTrue()
            ->and(Secrets::$suspended)->toBeFalse()
            ->and($cached)->not->toContain(CACHE_TEST_SECRET)
            // The fresh app booted without the overlay: not even registry defaults are cached.
            ->and((require app()->getCachedConfigPath())['services']['payme']['mode'])->toBeEmpty()
            ->and((require app()->getCachedConfigPath())['ai-assist']['limits']['daily_text'])->toBeEmpty();
    })->with([
        'plain' => [[]],
        'verbose' => [['-v' => true]],
    ]);

    it('does not overlay while suspended', function () {
        Secrets::$suspended = true;

        freshSecrets();

        expect(config('services.anthropic.key'))->toBeNull()
            ->and(config('services.payme.mode'))->toBeNull();
    });

    it('resolves abbreviations and nesting of the config-caching commands', function () {
        Secrets::suspendForCommand('config:ca', true);
        expect(Secrets::$suspended)->toBeTrue();
        Secrets::suspendForCommand('config:cache', false);
        expect(Secrets::$suspended)->toBeFalse();

        Secrets::suspendForCommand('optimize', true);
        Secrets::suspendForCommand('config:cache', true);
        Secrets::suspendForCommand('config:cache', false);
        expect(Secrets::$suspended)->toBeTrue();
        Secrets::suspendForCommand('optimize', false);
        expect(Secrets::$suspended)->toBeFalse();

        Secrets::suspendForCommand('route:list', true);
        expect(Secrets::$suspended)->toBeFalse();
    });
});

describe('secrets:reencrypt', function () {
    it('re-encrypts secrets saved with a previous key and reports unreadable ones by name', function () {
        $oldKey = Encrypter::generateKey('AES-256-CBC');
        $old = new Encrypter($oldKey, 'AES-256-CBC');

        DB::table('integration_secrets')->where('key', 'anthropic.api_key')->update(['value' => $old->encrypt(CACHE_TEST_SECRET, false)]);
        DB::table('integration_secrets')->insert(['key' => 'openai.api_key', 'value' => (new Encrypter(Encrypter::generateKey('AES-256-CBC'), 'AES-256-CBC'))->encrypt('sk-fake-LOSTKEY-0000000000000', false), 'created_at' => now(), 'updated_at' => now()]);

        config(['app.previous_keys' => ['base64:'.base64_encode($oldKey)]]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');

        $this->artisan('secrets:reencrypt')
            ->expectsOutputToContain('Re-encrypted 1 integration secret')
            ->expectsOutputToContain('openai.api_key')
            ->doesntExpectOutputToContain('LOSTKEY')
            ->doesntExpectOutputToContain('CONFIGCACHE')
            ->assertFailed();

        $current = new Encrypter(base64_decode(substr((string) config('app.key'), 7)), 'AES-256-CBC');
        $raw = DB::table('integration_secrets')->where('key', 'anthropic.api_key')->value('value');

        expect($current->decrypt($raw, false))->toBe(CACHE_TEST_SECRET)
            ->and(fn () => $old->decrypt($raw, false))->toThrow(DecryptException::class)
            ->and(Secrets::get('anthropic.api_key'))->toBe(CACHE_TEST_SECRET);
    });

    it('succeeds when every secret is readable', function () {
        expect(app(ReencryptSecrets::class)->handle())->toBe(['reencrypted' => 1, 'failed' => []])
            ->and(Secrets::get('anthropic.api_key'))->toBe(CACHE_TEST_SECRET);
    });
});
