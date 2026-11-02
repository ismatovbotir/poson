<?php

namespace App\Support\Api;

use App\Actions\Api\SyncPrices;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\LimitJsonBody;

/**
 * Static examples for the "How to use the API" panel (Settings → API tokens).
 * Contract: .claude/docs/api/prices.md. Everything here is constant: the
 * token is a placeholder (never built from an ApiToken), the endpoint comes
 * from config('app.url') and the named route (never from the request), and
 * limits come from the constants the middleware, action and throttle use.
 */
class PriceApiDocs
{
    public const PLACEHOLDER_TOKEN = 'pos_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX';

    public static function endpoint(): string
    {
        return rtrim((string) config('app.url'), '/').route('api.v1.prices', absolute: false);
    }

    /**
     * @return array{items: int, body_kb: int, per_minute: int, failures: int}
     */
    public static function limits(): array
    {
        return [
            'items' => SyncPrices::MAX_ITEMS,
            'body_kb' => intdiv(LimitJsonBody::MAX_BYTES, 1024),
            'per_minute' => SyncPrices::RATE_PER_MINUTE,
            'failures' => AuthenticateApiToken::MAX_FAILURES,
        ];
    }

    public static function requestJson(): string
    {
        return self::json([
            'items' => [
                ['code' => 258, 'retail' => 7450000, 'dealer' => 7100000, 'wholesale' => 6900000],
                ['code' => 1042, 'retail' => 1890000, 'dealer' => null, 'wholesale' => 0],
            ],
        ]);
    }

    public static function responseJson(): string
    {
        return self::json([
            'sync_id' => 1532,
            'received' => 4,
            'updated' => 1,
            'unchanged' => 1,
            'unknown' => 1,
            'invalid' => 1,
            'items' => [
                ['code' => '258', 'status' => 'updated'],
                ['code' => '1042', 'status' => 'unchanged'],
                ['code' => '99999', 'status' => 'unknown'],
                ['code' => '777', 'status' => 'invalid', 'errors' => ['retail_not_integer']],
            ],
        ]);
    }

    public static function curl(): string
    {
        return 'export POSON_TOKEN='.self::PLACEHOLDER_TOKEN."\n\n".implode(" \\\n", [
            'curl -sS -X POST "'.self::endpoint().'"',
            '  -H "Authorization: Bearer $POSON_TOKEN"',
            '  -H "Content-Type: application/json"',
            '  -H "Accept: application/json"',
            "  --data '".json_encode([
                'items' => [['code' => 258, 'retail' => 7450000, 'dealer' => 7100000, 'wholesale' => null]],
            ], JSON_UNESCAPED_SLASHES)."'",
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function json(array $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
