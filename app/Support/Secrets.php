<?php

namespace App\Support;

use App\Enums\IntegrationSource;
use App\Models\IntegrationSecret;
use App\Models\Setting;
use App\Support\Integrations\IntegrationField;
use App\Support\Integrations\IntegrationRegistry;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * The single resolver for integration values (plan 04 §3, S2–S5).
 *
 * For every IntegrationRegistry field the effective value is:
 *   1. the .env value (as read by the config files) when it is non-empty —
 *      it "locks" the field in admin;
 *   2. otherwise the value saved in Settings → Integrations (secrets from
 *      integration_secrets, decrypted here; non-secrets from settings);
 *   3. otherwise the registry default.
 * apply() (AppServiceProvider::boot) writes it to the field's config paths,
 * so code keeps reading config('services.…'). Use Secrets::get() where the
 * source matters; never env() outside config files.
 *
 * Caching: one entry ("integration_settings") holds the ciphertexts, hints
 * and validated non-secret values plus the version stamp they were read
 * at — never a decrypted secret. flush() (after each committed change)
 * drops it and writes a new version to "integration_settings_version"; a
 * Queue::before listener calls reapplyIfStale(), so long-running workers
 * pick up changes before their next job.
 *
 * A ciphertext that can't be decrypted (APP_KEY rotated) counts as "not
 * configured" and is reported once an hour, without the value. A .env
 * value of a select field outside its options counts as not set (reported
 * by key name only).
 *
 * Suspended while `config:cache` / `optimize` run (a CommandStarting
 * listener, see suspendForCommand()): those commands bootstrap a fresh app
 * whose config is written to bootstrap/cache/config.php, so the overlay
 * must not run there, or DB secrets would land in that file in plain text
 * and later look like .env values. $suspended is static on purpose: the
 * fresh app gets its own Secrets instance in the same PHP process.
 *
 * Registered as a singleton (one per process), so the captured .env values
 * and the applied version survive between queued jobs.
 */
final class Secrets
{
    public const CACHE_KEY = 'integration_settings';

    public const VERSION_KEY = 'integration_settings_version';

    /**
     * Lifetime of the cached values in seconds (a safety net; flush() is the
     * real invalidation).
     */
    public const CACHE_TTL = 3600;

    /**
     * Commands that build the config cache (the overlay must not run in them).
     */
    public const CONFIG_CACHE_COMMANDS = ['config:cache', 'optimize'];

    /**
     * True while a config-caching command runs in this PHP process.
     */
    public static bool $suspended = false;

    /**
     * .env values (raw, from config) captured before the first overlay.
     *
     * @var array<string, mixed>|null
     */
    private ?array $envValues = null;

    /**
     * Stored values: ciphertexts + hints and validated non-secret values.
     *
     * @var array{secrets: array<string, array{value: string, hint: string|null}>, settings: array<string, mixed>}|null
     */
    private ?array $stored = null;

    /**
     * Version stamp the stored values were read at (null: storage unavailable).
     */
    private ?string $appliedVersion = null;

    /**
     * Decrypted secrets of this process, keyed by field key (false = unreadable).
     *
     * @var array<string, string|false>
     */
    private array $decrypted = [];

    /**
     * Keys whose invalid .env value was reported by this instance.
     *
     * @var array<string, true>
     */
    private array $invalidEnvReported = [];

    public function __construct()
    {
        IntegrationRegistry::forget();
    }

    /**
     * Resolve every field and write the effective values to config.
     */
    public static function apply(): void
    {
        self::instance()->applyAll();
    }

    /**
     * Effective value of a registry field (cast to its type), or null.
     */
    public static function get(string $key): mixed
    {
        return self::instance()->resolve(IntegrationRegistry::field($key))[1];
    }

    /**
     * Where the effective value comes from.
     */
    public static function source(string $key): IntegrationSource
    {
        return self::instance()->resolve(IntegrationRegistry::field($key))[0];
    }

    /**
     * The field has a usable value (from .env, admin or a default).
     */
    public static function isConfigured(string $key): bool
    {
        return IntegrationField::isFilled(self::get($key));
    }

    /**
     * Last 4 characters of a secret saved in admin (values of 20+ characters
     * only); null for .env values, short values and unset fields.
     */
    public static function hint(string $key): ?string
    {
        $instance = self::instance();

        if ($instance->resolve(IntegrationRegistry::field($key))[0] !== IntegrationSource::Db) {
            return null;
        }

        return $instance->stored()['secrets'][$key]['hint'] ?? null;
    }

    /**
     * A secret is saved in admin but can't be decrypted (APP_KEY changed).
     */
    public static function isUnreadable(string $key): bool
    {
        $field = IntegrationRegistry::field($key);
        $instance = self::instance();

        return $field->secret
            && isset($instance->stored()['secrets'][$key])
            && $instance->decrypt($field) === null;
    }

    /**
     * The non-secret value saved in admin (validated), or null. Never
     * returns a secret.
     */
    public static function storedSetting(string $key): mixed
    {
        $field = IntegrationRegistry::field($key);

        return $field->secret ? null : $field->cast(self::instance()->stored()['settings'][$key] ?? null);
    }

    /**
     * Drop the cache, publish a new version (queue workers re-apply) and
     * re-apply in this process. Call after the change has committed.
     */
    public static function flush(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
            Cache::forever(self::VERSION_KEY, self::newVersion());
        } catch (QueryException|PDOException $e) {
            report($e);
        }

        if (app()->resolved(self::class)) {
            self::instance()->reload();
        }
    }

    /**
     * Re-apply when another process published a new version (Queue::before).
     */
    public static function reapplyIfStale(): void
    {
        $instance = self::instance();

        try {
            $current = Cache::get(self::VERSION_KEY);
        } catch (QueryException|PDOException) {
            return;
        }

        if ($current === null || $current !== $instance->appliedVersion) {
            $instance->reload();
        }
    }

    /**
     * CommandStarting / CommandFinished listener: suspend the overlay while
     * a config-caching command runs. Symfony resolves abbreviations
     * ("config:ca") before the event; aliases are mapped to the command
     * name through Artisan::all(). Covers Artisan::call() and nested calls
     * (optimize → config:cache).
     */
    public static function suspendForCommand(?string $command, bool $starting): void
    {
        $name = self::resolveCommandName((string) $command);

        if (! in_array($name, self::CONFIG_CACHE_COMMANDS, true)) {
            return;
        }

        // Depth counter: optimize → config:cache nests the events.
        self::$suspendDepth = max(0, self::$suspendDepth + ($starting ? 1 : -1));
        self::$suspended = self::$suspendDepth > 0;
    }

    /**
     * Number of config-caching commands currently running (nesting).
     */
    private static int $suspendDepth = 0;

    private static function resolveCommandName(string $name): string
    {
        if ($name === '') {
            return '';
        }

        try {
            $commands = Artisan::all();
        } catch (Throwable) {
            return $name;
        }

        if (isset($commands[$name])) {
            return (string) $commands[$name]->getName();
        }

        // Unambiguous abbreviation of a command name.
        $matches = array_values(array_filter(array_keys($commands), fn (string $candidate): bool => str_starts_with($candidate, $name)));

        return count($matches) === 1 ? (string) $commands[$matches[0]]->getName() : $name;
    }

    public static function version(): ?string
    {
        return self::instance()->appliedVersion;
    }

    private static function instance(): self
    {
        return app(self::class);
    }

    private static function newVersion(): string
    {
        return Str::random(24);
    }

    private function reload(): void
    {
        $this->stored = null;
        $this->decrypted = [];
        $this->applyAll();
    }

    private function applyAll(): void
    {
        // Never overlay while the config cache is built: DB secrets would be
        // written to bootstrap/cache/config.php and then look like .env values.
        if (self::$suspended) {
            return;
        }

        $this->captureEnv();

        foreach (IntegrationRegistry::fields() as $field) {
            $value = $this->resolve($field)[1];

            foreach ($field->configPaths as $path) {
                config([$path => $value]);
            }
        }
    }

    /**
     * Read the .env values from config once, before anything is overlaid.
     */
    private function captureEnv(): void
    {
        if ($this->envValues !== null) {
            return;
        }

        $this->envValues = [];

        foreach (IntegrationRegistry::fields() as $field) {
            $this->envValues[$field->key] = config($field->configPath());
        }
    }

    /**
     * @return array{0: IntegrationSource, 1: mixed}
     */
    private function resolve(IntegrationField $field): array
    {
        $this->captureEnv();

        $env = $this->envValues[$field->key] ?? null;

        if (IntegrationField::isFilled($env)) {
            $value = $field->cast($env);

            if ($field->type !== IntegrationField::TYPE_SELECT || in_array($value, $field->options, true)) {
                return [IntegrationSource::Env, $value];
            }

            // A select value outside its options counts as not set.
            $this->reportInvalidEnv($field);
        }

        $stored = $field->secret
            ? $this->decrypt($field)
            : $field->cast($this->stored()['settings'][$field->key] ?? null);

        if (IntegrationField::isFilled($stored)) {
            return [IntegrationSource::Db, $stored];
        }

        if (IntegrationField::isFilled($field->default)) {
            return [IntegrationSource::Default, $field->default];
        }

        return [IntegrationSource::None, null];
    }

    /**
     * Decrypted secret saved in admin, memoized; null when there is none or
     * it can't be decrypted (reported without the value).
     */
    private function decrypt(IntegrationField $field): ?string
    {
        if (! array_key_exists($field->key, $this->decrypted)) {
            $ciphertext = $this->stored()['secrets'][$field->key]['value'] ?? null;

            if ($ciphertext === null) {
                return null;
            }

            try {
                $value = IntegrationSecret::currentEncrypter()->decrypt($ciphertext, false);
                $this->decrypted[$field->key] = is_string($value) ? $value : false;
            } catch (DecryptException) {
                $this->decrypted[$field->key] = false;
                $this->reportUnreadable($field->key);
            }
        }

        $value = $this->decrypted[$field->key];

        return $value === false ? null : $value;
    }

    private function reportInvalidEnv(IntegrationField $field): void
    {
        if (isset($this->invalidEnvReported[$field->key])) {
            return;
        }

        $this->invalidEnvReported[$field->key] = true;

        report(new RuntimeException("Integration setting [{$field->key}] has a .env value ({$field->env}) outside its allowed options; it is ignored."));
    }

    private function reportUnreadable(string $key): void
    {
        try {
            if (! Cache::add('integration_secret_unreadable:'.$key, true, 3600)) {
                return;
            }
        } catch (QueryException|PDOException) {
            // Report anyway.
        }

        report(new RuntimeException("Integration secret [{$key}] can't be decrypted (was APP_KEY changed?). It is treated as not configured; replace it in Settings → Integrations."));
    }

    /**
     * @return array{secrets: array<string, array{value: string, hint: string|null}>, settings: array<string, mixed>}
     */
    private function stored(): array
    {
        return $this->stored ??= $this->load();
    }

    /**
     * Cached values for the current version; read from the DB when the cache
     * is empty or older. Without the tables (fresh install, migrations) the
     * defaults are used, nothing is cached and the next job retries.
     *
     * @return array{secrets: array<string, array{value: string, hint: string|null}>, settings: array<string, mixed>}
     */
    private function load(): array
    {
        $empty = ['secrets' => [], 'settings' => []];

        try {
            $version = Cache::get(self::VERSION_KEY);

            if (! is_string($version)) {
                Cache::add(self::VERSION_KEY, self::newVersion());
                $version = (string) Cache::get(self::VERSION_KEY);
            }

            $data = Cache::get(self::CACHE_KEY);

            if (! is_array($data) || ($data['version'] ?? null) !== $version) {
                $data = ['version' => $version, ...$this->query()];
                Cache::put(self::CACHE_KEY, $data, now()->addSeconds(self::CACHE_TTL));
            }

            $this->appliedVersion = $version;

            return ['secrets' => $data['secrets'] ?? [], 'settings' => $data['settings'] ?? []];
        } catch (QueryException|PDOException $e) {
            if (! app()->runningInConsole()) {
                report($e);
            }

            $this->appliedVersion = null;

            return $empty;
        }
    }

    /**
     * Raw rows: ciphertexts (never decrypted here) and non-secret values
     * that still pass their field rules.
     *
     * @return array{secrets: array<string, array{value: string, hint: string|null}>, settings: array<string, mixed>}
     */
    private function query(): array
    {
        $secrets = [];

        foreach (IntegrationSecret::query()->toBase()->whereIn('key', array_keys(IntegrationRegistry::secrets()))->get(['key', 'value', 'hint']) as $row) {
            $secrets[$row->key] = ['value' => (string) $row->value, 'hint' => $row->hint !== null ? (string) $row->hint : null];
        }

        $settings = [];
        $fields = IntegrationRegistry::settings();

        foreach (Setting::query()->toBase()->where('group', 'integrations')->whereIn('key', array_keys($fields))->get(['key', 'value']) as $row) {
            $value = $fields[$row->key]->cast(json_decode((string) $row->value, true));

            if ($value !== null && Validator::make(['value' => $value], ['value' => $fields[$row->key]->rules])->passes()) {
                $settings[$row->key] = $value;
            }
        }

        return ['secrets' => $secrets, 'settings' => $settings];
    }
}
