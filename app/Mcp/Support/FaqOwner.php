<?php

namespace App\Mcp\Support;

use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;

/**
 * The FAQ owner argument of the MCP tools (list_faq, create_faq_draft):
 * "general" (default), "category:ID", "product:ID" or "page:ID". The
 * tools validate it with PATTERN first.
 */
final class FaqOwner
{
    public const GENERAL = 'general';

    public const PATTERN = '/^(general|(category|product|page):[0-9]{1,10})$/';

    /**
     * The given spec, or "general" when empty or not a string.
     */
    public static function spec(mixed $value): string
    {
        return is_string($value) && $value !== '' ? $value : self::GENERAL;
    }

    /**
     * The owner model of a non-general spec; null when the spec is general,
     * malformed or points to a record that doesn't exist (trashed products too).
     */
    public static function model(string $spec): ?Model
    {
        if (! preg_match('/^(category|product|page):([0-9]{1,10})$/', $spec, $match)) {
            return null;
        }

        return match ($match[1]) {
            'category' => Category::query()->find((int) $match[2]),
            'product' => Product::query()->find((int) $match[2]),
            default => Page::query()->find((int) $match[2]),
        };
    }
}
