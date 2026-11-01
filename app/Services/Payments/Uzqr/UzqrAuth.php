<?php

namespace App\Services\Payments\Uzqr;

/**
 * The UzQR "Auth" header (bank doc §2.2): "{INN}:{digest}:{timestamp}" with
 * digest = lowercase hex sha1(timestamp . API_key) and a 10-digit UNIX
 * timestamp. Built fresh for every request (security condition 31); the
 * header, digest and key are never logged, cached, stored or put into an
 * exception (the key is a #[\SensitiveParameter]).
 */
final class UzqrAuth
{
    public const HEADER = 'Auth';

    /**
     * Merchant STIR (9 digits, first 2–6) or PINFL (14 digits, first 3–6), condition 32.
     */
    public const INN = '/^(?:[2-6]\d{8}|[3-6]\d{13})$/';

    public static function header(string $inn, #[\SensitiveParameter] string $apiKey, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return $inn.':'.sha1($timestamp.$apiKey).':'.$timestamp;
    }

    public static function validInn(mixed $inn): bool
    {
        return is_string($inn) && preg_match(self::INN, $inn) === 1;
    }
}
