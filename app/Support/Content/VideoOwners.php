<?php

namespace App\Support\Content;

use App\Models\Brand;
use App\Models\Category;
use App\Models\NewsPost;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The models that can own videos (allow-list) and helpers to resolve and
 * touch them (touching the owner flushes the sitemap and records IndexNow).
 */
final class VideoOwners
{
    /**
     * @return list<class-string<Model>>
     */
    public static function classes(): array
    {
        return [Product::class, Category::class, Brand::class, NewsPost::class];
    }

    public static function allows(Model $owner): bool
    {
        return in_array($owner::class, self::classes(), true);
    }

    /**
     * Resolve an owner by morph alias and id (trashed products included).
     */
    public static function find(string $type, int $id): ?Model
    {
        $class = Relation::getMorphedModel($type);

        if ($class === null || ! in_array($class, self::classes(), true)) {
            return null;
        }

        $query = $class::query();

        if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
            $query->withTrashed();
        }

        return $query->find($id);
    }

    public static function touch(Model $owner): void
    {
        $owner->touch();
    }
}
