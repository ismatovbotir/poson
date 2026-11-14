<?php

namespace App\Support\Careers;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

/**
 * The application form's bot token (plan 31, CR-2):
 * Crypt::encryptString(json {t, vacancy_id | department, nonce}). Rendered
 * with the form; on submit it must decrypt, name the same target as the
 * URL, be at least MIN_SECONDS and at most MAX_SECONDS old and its nonce
 * must be unused (Cache::add, single use). Any failure reads as a bot: the
 * caller answers with the normal success and stores nothing.
 */
final class FormToken
{
    public const MIN_SECONDS = 2;

    public const MAX_SECONDS = 86400;

    public const NONCE_PREFIX = 'careers-nonce:';

    /**
     * @param  array{vacancy_id: int}|array{department: string}  $target
     */
    public static function issue(array $target): string
    {
        return Crypt::encryptString((string) json_encode([
            't' => now()->getTimestamp(),
            ...$target,
            'nonce' => Str::random(32),
        ]));
    }

    /**
     * Check the token for the target and use up its nonce; false = bot.
     *
     * @param  array{vacancy_id: int}|array{department: string}  $target
     */
    public static function consume(mixed $token, array $target): bool
    {
        if (! is_string($token) || $token === '' || strlen($token) > 1024) {
            return false;
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|Throwable) {
            return false;
        }

        if (! is_array($payload) || ! is_int($payload['t'] ?? null) || ! is_string($payload['nonce'] ?? null) || strlen($payload['nonce']) !== 32) {
            return false;
        }

        $key = array_key_first($target);

        if (! array_key_exists($key, $payload) || $payload[$key] !== $target[$key]
            || count(array_intersect(['vacancy_id', 'department'], array_keys($payload))) !== 1) {
            return false;
        }

        $age = now()->getTimestamp() - $payload['t'];

        if ($age < self::MIN_SECONDS || $age > self::MAX_SECONDS) {
            return false;
        }

        return Cache::add(self::NONCE_PREFIX.$payload['nonce'], true, self::MAX_SECONDS);
    }
}
