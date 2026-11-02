<?php

namespace App\Support\Content;

use App\Models\NewsPost;
use App\Rules\BrandSafeText;
use Illuminate\Validation\Rule;

/**
 * Validation rules of the content fields of a news post / article, shared
 * by the admin form (NewsPostForm) and the MCP create_post tool so both
 * accept exactly the same texts (brand rule, lengths, related records).
 */
final class NewsPostRules
{
    /**
     * Most related products / categories per post.
     */
    public const MAX_RELATED = 20;

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'title.uz' => ['required', 'string', 'max:255', new BrandSafeText],
            'title.ru' => ['nullable', 'string', 'max:255', new BrandSafeText],
            'title.en' => ['nullable', 'string', 'max:255', new BrandSafeText],
            'excerpt.*' => ['nullable', 'string', 'max:500', new BrandSafeText],
            'body.*' => ['nullable', 'string', 'max:'.NewsPost::BODY_MAX],
            'meta_title.*' => ['nullable', 'string', 'max:255', new BrandSafeText],
            'meta_description.*' => ['nullable', 'string', 'max:500', new BrandSafeText],
            'product_ids' => ['array', 'max:'.self::MAX_RELATED],
            'product_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'category_ids' => ['array', 'max:'.self::MAX_RELATED],
            'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')],
        ];
    }
}
