<?php

namespace App\Support\Analytics;

use App\Support\CompanySettings;
use App\Support\Site\Analytics;
use Illuminate\Http\Request;
use Throwable;

/**
 * Yandex ad click ids stored with a lead or an order (plan 40 §2, YD-6–YD-8):
 *
 *  - yclid: the last Direct click of the session (Attribution keeps it in
 *    the session, digits only, 1–32), never truncated, invalid = dropped;
 *  - ym_client_id: Metrika's _ym_uid cookie (10–24 digits), read only here
 *    and only when this page loads Metrika (Analytics::metrikaEnabled()).
 *    The cookie is set by Metrika's JS, excluded from EncryptCookies and
 *    never trusted for anything else.
 *
 * Both are null while the Admin switch analytics.ad_click_ids_enabled is
 * off. Never logged, never a public Livewire property, never shown raw.
 */
final class AdClickIds
{
    public const YCLID_PATTERN = '/^[0-9]{1,32}$/D';

    public const CLIENT_ID_PATTERN = '/^[0-9]{10,24}$/D';

    public const CLIENT_ID_COOKIE = '_ym_uid';

    public static function enabled(): bool
    {
        try {
            return app(CompanySettings::class)->adClickIdsEnabled();
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * The click ids for a lead / order created by this request.
     *
     * @return array{yclid: string|null, ym_client_id: string|null}
     */
    public static function capture(Request $request): array
    {
        $none = ['yclid' => null, 'ym_client_id' => null];

        if (! self::enabled()) {
            return $none;
        }

        try {
            $stored = app(Attribution::class)->stored($request) ?? [];
            $cookies = $request->cookies->all();

            return [
                'yclid' => self::cleanYclid($stored['yclid'] ?? null),
                'ym_client_id' => app(Analytics::class)->metrikaEnabled() ? self::cleanClientId($cookies[self::CLIENT_ID_COOKIE] ?? null) : null,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return $none;
        }
    }

    public static function cleanYclid(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::YCLID_PATTERN, $value) === 1 ? $value : null;
    }

    public static function cleanClientId(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::CLIENT_ID_PATTERN, $value) === 1 ? $value : null;
    }
}
