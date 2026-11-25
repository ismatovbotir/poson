<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Text encrypted at rest with APP_KEY (plan 41, VO-5), like Laravel's
 * "encrypted" cast, but a value that cannot be decrypted (a rotated key
 * that is no longer in APP_PREVIOUS_KEYS, a damaged row) reads as the
 * UNREADABLE placeholder instead of throwing, so one bad row never breaks
 * a screen, a job or the prune. Callers compare with UNREADABLE and never
 * send it to the model. Key rotation: put the old key in APP_PREVIOUS_KEYS
 * (the encrypter tries it when decrypting) until the rows have expired.
 */
class EncryptedText implements CastsAttributes
{
    public const UNREADABLE = "\u{FFFD}unreadable";

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString((string) $value);
        } catch (DecryptException) {
            return self::UNREADABLE;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Crypt::encryptString((string) $value);
    }

    public static function isReadable(?string $value): bool
    {
        return $value !== null && $value !== '' && $value !== self::UNREADABLE;
    }
}
