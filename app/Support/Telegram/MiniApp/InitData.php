<?php

namespace App\Support\Telegram\MiniApp;

use JsonException;

/**
 * Verifies the initData Telegram passes to a Mini App (plan 20 §4, MA-2):
 * https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
 *
 * Strict parsing: at most MAX_BYTES; split on "&", then on the first "=",
 * then rawurldecode(); an empty key, an empty pair, a duplicate key or a
 * missing hash / auth_date / user is refused. The data-check string is every
 * pair except "hash" (so "signature" is included), sorted by key, joined
 * with "\n" as "key=value". secret = HMAC-SHA256(bot token, key "WebAppData"),
 * hash = hex HMAC-SHA256(data-check string, secret), compared with
 * hash_equals(). Fresh only while now - auth_date <= telegram.mini_app.auth_ttl
 * and auth_date <= now + future_skew. "user" is JSON of depth 3 at most with a
 * positive integer id. start_param is kept only when it matches
 * TmaTarget::PATTERN (MA-7), otherwise dropped.
 *
 * The caller must refuse the request (503) when no bot token is configured;
 * verify() itself never runs without one.
 */
final class InitData
{
    public const MAX_BYTES = 4096;

    public const REQUIRED = ['hash', 'auth_date', 'user'];

    public static function verify(string $raw, string $botToken, ?int $now = null): VerifiedInitData|InvalidInitData
    {
        $now ??= now()->getTimestamp();

        if (strlen($raw) > self::MAX_BYTES) {
            return new InvalidInitData(InvalidInitData::TOO_LONG);
        }

        $fields = self::parse($raw);

        if ($fields instanceof InvalidInitData) {
            return $fields;
        }

        foreach (self::REQUIRED as $key) {
            if (! isset($fields[$key]) || $fields[$key] === '') {
                return new InvalidInitData(InvalidInitData::MISSING);
            }
        }

        $hash = $fields['hash'];

        if (preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1 || ! hash_equals(self::sign($fields, $botToken), $hash)) {
            return new InvalidInitData(InvalidInitData::SIGNATURE);
        }

        if (preg_match('/\A[1-9][0-9]{0,11}\z/', $fields['auth_date']) !== 1) {
            return new InvalidInitData(InvalidInitData::MALFORMED);
        }

        $authDate = (int) $fields['auth_date'];

        if ($authDate > $now + (int) config('telegram.mini_app.future_skew', 60)) {
            return new InvalidInitData(InvalidInitData::FUTURE);
        }

        if ($now - $authDate > (int) config('telegram.mini_app.auth_ttl', 3600)) {
            return new InvalidInitData(InvalidInitData::EXPIRED);
        }

        try {
            $user = json_decode($fields['user'], true, 3, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new InvalidInitData(InvalidInitData::USER);
        }

        if (! is_array($user) || ! is_int($user['id'] ?? null) || $user['id'] <= 0) {
            return new InvalidInitData(InvalidInitData::USER);
        }

        $language = is_string($user['language_code'] ?? null) ? strtolower(substr($user['language_code'], 0, 2)) : null;
        $startParam = $fields['start_param'] ?? null;

        return new VerifiedInitData(
            telegramId: $user['id'],
            authDate: $authDate,
            languageCode: in_array($language, (array) config('app.supported_locales'), true) ? $language : null,
            startParam: is_string($startParam) && TmaTarget::isValid($startParam) ? $startParam : null,
        );
    }

    /**
     * The hex signature of the fields (everything but "hash") under a bot token.
     *
     * @param  array<string, string>  $fields
     */
    public static function sign(array $fields, string $botToken): string
    {
        unset($fields['hash']);
        ksort($fields, SORT_STRING);

        $pairs = [];

        foreach ($fields as $key => $value) {
            $pairs[] = $key.'='.$value;
        }

        $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);

        return hash_hmac('sha256', implode("\n", $pairs), $secret);
    }

    /**
     * @return array<string, string>|InvalidInitData
     */
    private static function parse(string $raw): array|InvalidInitData
    {
        if ($raw === '') {
            return new InvalidInitData(InvalidInitData::MISSING);
        }

        $fields = [];

        foreach (explode('&', $raw) as $pair) {
            $position = strpos($pair, '=');

            if ($pair === '' || $position === false || $position === 0) {
                return new InvalidInitData(InvalidInitData::MALFORMED);
            }

            $key = rawurldecode(substr($pair, 0, $position));
            $value = rawurldecode(substr($pair, $position + 1));

            if ($key === '' || ! mb_check_encoding($key, 'UTF-8') || ! mb_check_encoding($value, 'UTF-8')) {
                return new InvalidInitData(InvalidInitData::MALFORMED);
            }

            if (array_key_exists($key, $fields)) {
                return new InvalidInitData(InvalidInitData::DUPLICATE);
            }

            $fields[$key] = $value;
        }

        return $fields;
    }
}
