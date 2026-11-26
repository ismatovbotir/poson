<?php

namespace App\Support\Telegram\MiniApp;

use App\Models\Category;
use Illuminate\Support\Facades\Vite;
use Throwable;

/**
 * Mini App icons (plan 45 A2, MA-48): one static SVG sprite,
 * resources/svg/tma-icons.svg, built by Vite into /build with a hashed name
 * and referenced from x-tma.icon as <use href="/build/assets/…svg#i-name">.
 *
 * The href is the path only, so the sprite is always fetched from the page's
 * own origin (browsers refuse a cross-origin <use>; ASSET_URL or a CDN would
 * break it). Covered by the CSP's 'self' whichever directive a browser applies.
 *
 * Without a built sprite (Vite running hot, the entry missing from the
 * manifest, withoutVite() in tests) the icons point at "#i-name" and the
 * layout inlines only the symbols the page used (inlineSprite()).
 *
 * Names: Category::ICONS plus UI; anything else is "grid".
 */
final class TmaIcons
{
    public const SPRITE = 'resources/svg/tma-icons.svg';

    public const FALLBACK = 'grid';

    /**
     * UI symbols of the sprite besides the category icons.
     *
     * @var list<string>
     */
    public const UI = ['search', 'cart', 'filter', 'sort', 'chevron', 'check', 'plus', 'minus', 'trash', 'close', 'send', 'clock'];

    /**
     * Names used on this page while the sprite is inlined.
     *
     * @var array<string, true>
     */
    private static array $used = [];

    /**
     * @var array<string, string>|null
     */
    private static ?array $symbols = null;

    public static function name(?string $name): string
    {
        return is_string($name) && (in_array($name, Category::ICONS, true) || in_array($name, self::UI, true)) ? $name : self::FALLBACK;
    }

    public static function href(?string $name): string
    {
        $name = self::name($name);
        $sprite = self::spriteUrl();

        if ($sprite === null) {
            self::$used[$name] = true;

            return '#i-'.$name;
        }

        return $sprite.'#i-'.$name;
    }

    /**
     * Same-origin path of the built sprite ("/build/assets/tma-icons-….svg"), or null.
     */
    public static function spriteUrl(): ?string
    {
        try {
            if (Vite::isRunningHot()) {
                return null;
            }

            $url = (string) Vite::asset(self::SPRITE);
        } catch (Throwable) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && preg_match('#\A/build/assets/[A-Za-z0-9._-]+\.svg\z#', $path) === 1 ? $path : null;
    }

    /**
     * The symbols this page used while the sprite can't be referenced (hidden
     * inline <svg>), then forgets them; "" when nothing is needed.
     */
    public static function inlineSprite(): string
    {
        $used = array_keys(self::$used);
        self::$used = [];

        if ($used === []) {
            return '';
        }

        $symbols = array_intersect_key(self::symbols(), array_flip($used));

        return $symbols === [] ? '' : '<svg width="0" height="0" class="absolute" aria-hidden="true" focusable="false">'.implode('', $symbols).'</svg>';
    }

    /**
     * Symbol markup of the sprite file keyed by icon name.
     *
     * @return array<string, string>
     */
    public static function symbols(): array
    {
        if (self::$symbols === null) {
            $svg = (string) @file_get_contents(resource_path('svg/tma-icons.svg'));
            preg_match_all('#<symbol id="i-([a-z0-9-]+)"[^>]*>.*?</symbol>#s', $svg, $matches, PREG_SET_ORDER);
            self::$symbols = [];

            foreach ($matches as $match) {
                self::$symbols[$match[1]] = $match[0];
            }
        }

        return self::$symbols;
    }
}
