<?php

namespace App\Support\Telegram\MiniApp;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\TelegramUser;
use App\Support\Catalog\CategoryTree;
use Illuminate\Routing\Route;

/**
 * Where the Mini App opens (plan 20 §7, MA-7): the signed start_param of a
 * deep link (t.me/<bot>?startapp=…) or the "next" of a re-authentication,
 * both from one closed grammar, resolved to an internal route only:
 *
 *  - p{id}   a product shown on the site → tma.product
 *  - c{id}   a visible category → tma.category
 *  - orders  → tma.orders
 *  - o{id}   the user's own order → tma.orders.show (else tma.orders)
 *
 * Anything else, or a target that no longer exists, is the home screen. The
 * order ULID never appears in a target (MA-17).
 */
final class TmaTarget
{
    public const PATTERN = '/\A(p\d{1,10}|c\d{1,10}|orders|o\d{1,10})\z/';

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * The URL for a target (null or invalid → the home screen) in the locale.
     */
    public static function url(?string $target, TelegramUser $user, string $locale): string
    {
        $home = route('tma.home', ['locale' => $locale]);

        if (! self::isValid($target)) {
            return $home;
        }

        if ($target === 'orders') {
            return route('tma.orders', ['locale' => $locale]);
        }

        $id = (int) substr((string) $target, 1);

        return match ($target[0]) {
            'p' => ($slug = Product::query()->visible()->whereKey($id)->value('products.slug')) !== null
                ? route('tma.product', ['locale' => $locale, 'product' => $slug])
                : $home,
            'c' => CategoryTree::isVisible($id)
                ? route('tma.category', ['locale' => $locale, 'category' => CategoryTree::find($id)['slug']])
                : $home,
            'o' => Order::query()->real()->where('telegram_user_id', $user->id)->whereKey($id)->exists()
                ? route('tma.orders.show', ['locale' => $locale, 'order' => $id])
                : route('tma.orders', ['locale' => $locale]),
            default => $home,
        };
    }

    /**
     * The target of the page a request is on, for "next" after a re-authentication.
     */
    public static function forRoute(?Route $route): ?string
    {
        if ($route === null) {
            return null;
        }

        // Bindings may not be substituted yet (EnsureTmaUser runs first): a model or its slug.
        $id = function (string $name, string $model) use ($route): ?int {
            $value = $route->parameter($name);

            if ($value instanceof $model) {
                return (int) $value->getKey();
            }

            $key = is_string($value) && strlen($value) <= 191 ? $model::query()->where('slug', $value)->value('id') : null;

            return $key !== null ? (int) $key : null;
        };

        $target = match ($route->getName()) {
            'tma.product', 'tma.service' => ($productId = $id('product', Product::class)) !== null ? 'p'.$productId : null,
            'tma.category', 'tma.category.filters' => ($categoryId = $id('category', Category::class)) !== null ? 'c'.$categoryId : null,
            'tma.orders' => 'orders',
            'tma.orders.show' => is_numeric($route->parameter('order')) ? 'o'.(int) $route->parameter('order') : 'orders',
            default => null,
        };

        return self::isValid($target) ? $target : null;
    }
}
