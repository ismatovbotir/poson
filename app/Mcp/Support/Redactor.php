<?php

namespace App\Mcp\Support;

/**
 * Tool arguments as stored in mcp_calls.arguments (plan 13 §4, C27):
 * - keys that look like credentials (token, password, secret, authorization,
 *   key, cookie) and any value shaped like a Poson token become "[redacted]";
 * - strings longer than MAX_STRING characters (or multi-line) become
 *   {"chars": n}, so content never sits in the audit log;
 * - nesting is cut at MAX_DEPTH and lists at MAX_ITEMS.
 * Ids, enums, booleans and numbers are kept as they are.
 */
final class Redactor
{
    public const REDACTED = '[redacted]';

    public const MAX_STRING = 64;

    public const MAX_DEPTH = 3;

    public const MAX_ITEMS = 50;

    private const SENSITIVE_KEY = '/(token|passw|secret|authori[sz]ation|api[_-]?key|cookie|bearer)/i';

    private const TOKEN_SHAPE = '/pos(m)?_[A-Za-z0-9]{16,}/';

    /**
     * @param  array<array-key, mixed>  $arguments
     * @return array<array-key, mixed>
     */
    public static function arguments(array $arguments): array
    {
        return self::walk($arguments, 0);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function walk(array $values, int $depth): array
    {
        $result = [];
        $count = 0;

        foreach ($values as $key => $value) {
            if (++$count > self::MAX_ITEMS) {
                $result['…'] = ['items' => count($values)];

                break;
            }

            $key = is_string($key) ? mb_substr($key, 0, 64) : $key;

            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key)) {
                $result[$key] = self::REDACTED;

                continue;
            }

            $result[$key] = self::value($value, $depth);
        }

        return $result;
    }

    private static function value(mixed $value, int $depth): mixed
    {
        if (is_array($value)) {
            return $depth + 1 >= self::MAX_DEPTH ? ['items' => count($value)] : self::walk($value, $depth + 1);
        }

        if (is_string($value)) {
            if (preg_match(self::TOKEN_SHAPE, $value)) {
                return self::REDACTED;
            }

            if (mb_strlen($value) > self::MAX_STRING || str_contains($value, "\n")) {
                return ['chars' => mb_strlen($value)];
            }

            return UntrustedText::clean($value, self::MAX_STRING) ?? '';
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        return self::REDACTED;
    }
}
