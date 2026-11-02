<?php

namespace App\Mcp\Support;

use App\Models\Brand;
use App\Models\Category;
use App\Models\NewsPost;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;

/**
 * The owner argument of the MCP attach_video tool: "product:ID", "category:ID",
 * "brand:ID" or "post:ID" (a news post or article). The tool validates it with
 * PATTERN first; an unknown id, a trashed product and a malformed spec all
 * resolve to null.
 */
final class VideoOwner
{
    public const PATTERN = '/\A(product|category|brand|post):[0-9]{1,10}\z/';

    public static function model(mixed $spec): ?Model
    {
        if (! is_string($spec) || preg_match('/\A(product|category|brand|post):([0-9]{1,10})\z/', $spec, $match) !== 1) {
            return null;
        }

        $id = (int) $match[2];

        return match ($match[1]) {
            'product' => Product::query()->find($id),
            'category' => Category::query()->find($id),
            'brand' => Brand::query()->find($id),
            default => NewsPost::query()->find($id),
        };
    }
}
