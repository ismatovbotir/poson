<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Support\DemoContent;
use Closure;

/**
 * Whether the demo products, news and banners seeders may run (plan 15 A2).
 *
 * Always on local and staging. In any other environment (production
 * included) only while LaunchSeeder runs them through
 * allowingProduction(): a code-only, per-process opt-in, never an env var
 * or config value. DemoLeadsSeeder ignores this and keeps its hard local /
 * staging check.
 */
final class DemoSeeding
{
    private static bool $allowedOutsideDev = false;

    public static function allowed(): bool
    {
        return app()->environment(['local', 'staging']) || self::$allowedOutsideDev;
    }

    /**
     * Production only: the demo products, news and banners must not be (re)created
     * once they were removed, or when real products exist that are not the seeded demo set.
     */
    public static function blockedInProduction(): bool
    {
        return app()->isProduction()
            && (DemoContent::wasRemoved() || (DemoContent::record() === null && Product::withTrashed()->exists()));
    }

    /**
     * Run the callback with the demo seeders allowed in this environment;
     * the opt-in is always switched off again afterwards.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function allowingProduction(Closure $callback): mixed
    {
        $previous = self::$allowedOutsideDev;
        self::$allowedOutsideDev = true;

        try {
            return $callback();
        } finally {
            self::$allowedOutsideDev = $previous;
        }
    }
}
