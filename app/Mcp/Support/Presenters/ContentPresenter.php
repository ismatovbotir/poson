<?php

namespace App\Mcp\Support\Presenters;

use App\Mcp\Support\Period;
use App\Models\FaqItem;
use App\Models\NewsPost;
use App\Models\Page;
use App\Support\Content\PostAudit;
use Carbon\CarbonInterface;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Explicit field allow-lists of the content tools (plan 13 S3, S21): no
 * author, user ids, emails, media paths or revisions, and never toArray().
 */
final class ContentPresenter
{
    public const BODY_MAX_BYTES = 20480;

    public const ANSWER_MAX_BYTES = 2048;

    /**
     * @return array<string, mixed>
     */
    public static function post(NewsPost $post): array
    {
        return [
            'id' => $post->id,
            'slug' => $post->slug,
            'kind' => $post->kind->value,
            'state' => $post->state()->value,
            'published_at' => self::date($post->published_at),
            'content_updated_at' => self::date($post->content_updated_at),
            'locales' => array_values(array_map('strval', (array) $post->locales)),
            'title' => Texts::translated($post, 'title'),
            'excerpt' => Texts::translated($post, 'excerpt', 500),
            'meta_title' => Texts::translated($post, 'meta_title'),
            'meta_description' => Texts::translated($post, 'meta_description', 300),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function postListItem(NewsPost $post): array
    {
        return [...self::post($post), 'seo_issue_count' => PostAudit::count($post)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function page(Page $page): array
    {
        return [
            'id' => $page->id,
            'slug' => $page->slug,
            'template' => $page->template->value,
            'is_system' => $page->is_system,
            'is_active' => $page->is_active,
            'needs_review' => $page->needs_review,
            'sort' => $page->sort,
            'content_updated_at' => self::date($page->contentUpdatedAt()),
            'title' => Texts::translated($page, 'title'),
            'meta_title' => Texts::translated($page, 'meta_title'),
            'meta_description' => Texts::translated($page, 'meta_description', 300),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function faq(FaqItem $item): array
    {
        return [
            'id' => $item->id,
            'sort' => $item->sort,
            'is_active' => $item->is_active,
            'question' => Texts::translated($item, 'question', FaqItem::QUESTION_MAX),
        ];
    }

    public static function date(?CarbonInterface $date): ?string
    {
        return $date?->copy()->setTimezone(Period::TIMEZONE)->toIso8601String();
    }

    /**
     * @return array<string, mixed>
     */
    public static function postSchema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
            'slug' => $schema->string()->required(),
            'kind' => $schema->string()->required(),
            'state' => $schema->string()->required(),
            'published_at' => $schema->string()->nullable(),
            'content_updated_at' => $schema->string()->nullable(),
            'locales' => $schema->array()->items($schema->string())->required(),
            'title' => Texts::schema($schema),
            'excerpt' => Texts::schema($schema),
            'meta_title' => Texts::schema($schema),
            'meta_description' => Texts::schema($schema),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function pageSchema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
            'slug' => $schema->string()->required(),
            'template' => $schema->string()->required(),
            'is_system' => $schema->boolean()->required(),
            'is_active' => $schema->boolean()->required(),
            'needs_review' => $schema->boolean()->required(),
            'sort' => $schema->integer()->required(),
            'content_updated_at' => $schema->string()->nullable(),
            'title' => Texts::schema($schema),
            'meta_title' => Texts::schema($schema),
            'meta_description' => Texts::schema($schema),
        ];
    }
}
