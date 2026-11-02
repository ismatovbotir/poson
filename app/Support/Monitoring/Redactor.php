<?php

namespace App\Support\Monitoring;

use App\Support\Integrations\IntegrationRegistry;
use App\Support\Secrets;
use Throwable;

/**
 * Makes error text and task output safe to store and show (plan 17 §6,
 * MON-S5). Applied to run errors, stored output, failed-job exceptions and
 * Telegram alerts alike. Order matters:
 *
 *  1. control characters stripped;
 *  2. optionally cut at "Stack trace:" / "#0 ";
 *  3. literal values of every Secrets registry secret (and APP_KEY, the DB
 *     password) replaced first, so a leaked key is removed whatever it
 *     looks like;
 *  4. bot token, text after "(Connection:" / "SQL:" (SQLSTATE stays),
 *     base_path() → "~", Auth headers, Bearer/Basic, key=value secrets,
 *     query strings, emails, phone numbers (any country), long hex/base64 runs, 7+ digit
 *     runs;
 *  5. mb_substr to the limit.
 */
final class Redactor
{
    public const MASK = '[redacted]';

    /**
     * Shorter secrets would shred ordinary text.
     */
    private const MIN_LITERAL = 8;

    public static function clean(?string $text, int $limit = 300, bool $cutTrace = true): string
    {
        $text = mb_scrub((string) $text);
        $text = (string) preg_replace('/[^\P{C}\n\t]/u', '', str_replace("\r", '', $text));

        if ($cutTrace) {
            $text = self::before($text, ['Stack trace:', '#0 ']);
        }

        foreach (self::literals() as $literal) {
            $text = str_replace($literal, self::MASK, $text);
        }

        $text = (string) preg_replace('/\d{5,15}:[A-Za-z0-9_-]{30,}/', '[token]', $text);
        $text = self::before($text, ['(Connection:', 'SQL:'], '(…)');
        $text = str_replace(base_path(), '~', $text);

        $text = (string) preg_replace('/\b((?:Proxy-)?Authorization|Auth|X-[\w-]*(?:Key|Token|Secret|Auth)[\w-]*)[ \t]*:[ \t]*[^\n]+/i', '$1: '.self::MASK, $text);
        $text = (string) preg_replace('/\b(Bearer|Basic)[ \t]+[A-Za-z0-9._~+\/=-]+/i', '$1 '.self::MASK, $text);
        $text = (string) preg_replace('/\b([\w.-]*(?:password|passwd|secret|token|key|sign\w*|auth\w*)[\w.-]*)=("[^"]*"|\'[^\']*\'|[^\s&,;]*)/i', '$1='.self::MASK, $text);
        $text = (string) preg_replace('/\?[^\s"\'<>]*=[^\s"\'<>]*/', '?[query]', $text);
        $text = (string) preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/', '[email]', $text);
        // TG-46: any international number: "+" then 8-15 digits, separators allowed.
        // The 00 form must not follow a digit (linear: one class repeated, no nesting).
        $text = (string) preg_replace('/(?:\+|(?<!\d)00)(?:[ ().-]*\d){8,15}/', '[phone]', $text);
        $text = (string) preg_replace('/\+?998(?:[ ().-]*\d){9}/', '[phone]', $text);
        $text = (string) preg_replace('/\b[A-Fa-f0-9]{24,}\b/', '[hash]', $text);
        $text = (string) preg_replace('/(?=[A-Za-z0-9+_-]*\d)(?=[A-Za-z0-9+_-]*[A-Za-z])[A-Za-z0-9+_-]{24,}={0,2}/', '[hash]', $text);
        $text = (string) preg_replace('/\d{7,}/', '[number]', $text);

        return trim(mb_substr($text, 0, max(0, $limit)));
    }

    /**
     * The first non-empty line, redacted (stack trace cut off).
     */
    public static function line(?string $text, int $limit = 300): string
    {
        $clean = self::clean($text, 10000);

        foreach (explode("\n", $clean) as $line) {
            if (trim($line) !== '') {
                return trim(mb_substr(trim($line), 0, max(0, $limit)));
            }
        }

        return '';
    }

    /**
     * @param  list<string>  $markers
     */
    private static function before(string $text, array $markers, string $suffix = ''): string
    {
        $cut = null;

        foreach ($markers as $marker) {
            $position = mb_stripos($text, $marker);

            if ($position !== false && ($cut === null || $position < $cut)) {
                $cut = $position;
            }
        }

        return $cut === null ? $text : rtrim(mb_substr($text, 0, $cut)).($suffix === '' ? '' : ' '.$suffix);
    }

    /**
     * Literal values that must never appear in stored text, longest first.
     *
     * @return list<string>
     */
    private static function literals(): array
    {
        $values = [
            config('app.key'),
            config('database.connections.'.config('database.default').'.password'),
            config('mail.mailers.smtp.password'),
            config('database.redis.default.password'),
            config('database.redis.cache.password'),
            ...(array) config('app.previous_keys', []),
        ];

        try {
            foreach (IntegrationRegistry::secrets() as $key => $field) {
                $values[] = Secrets::get($key);
            }
        } catch (Throwable) {
            // The resolver needs the database; the pattern rules below still apply.
        }

        $forms = [];

        foreach ($values as $value) {
            if (! is_string($value) || strlen($value) < self::MIN_LITERAL) {
                continue;
            }

            // As configured, without the "base64:" prefix of keys, JSON-escaped and URL-encoded.
            foreach ([$value, str_starts_with($value, 'base64:') ? substr($value, 7) : $value, trim((string) json_encode($value), '"'), rawurlencode($value)] as $form) {
                if (strlen($form) >= self::MIN_LITERAL) {
                    $forms[$form] = true;
                }
            }
        }

        $forms = array_map('strval', array_keys($forms));
        usort($forms, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $forms;
    }
}
