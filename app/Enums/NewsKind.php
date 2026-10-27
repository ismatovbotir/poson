<?php

namespace App\Enums;

use Illuminate\Support\Facades\Lang;

/**
 * Kind of a news_posts row (decision 2026-10-24): a dated company news post
 * (/{locale}/news) or an evergreen knowledge article such as a buying guide
 * or a how-to (/{locale}/articles). Both share the model, the actions, the
 * permissions (news.*) and one global slug space; the admin screen and the
 * public route fix the kind, and a post opened on the other kind's route is
 * a 404. RSS and the home "Latest news" stay news-only.
 */
enum NewsKind: string
{
    case News = 'news';
    case Article = 'article';

    public function label(): string
    {
        return __("enums.news_kind.{$this->value}");
    }

    /**
     * URL segment and route name prefix: "news" or "articles".
     */
    public function segment(): string
    {
        return match ($this) {
            self::News => 'news',
            self::Article => 'articles',
        };
    }

    /**
     * Public route name: site.news.show, site.articles.index, ...
     */
    public function siteRoute(string $name): string
    {
        return 'site.'.$this->segment().'.'.$name;
    }

    /**
     * Admin route name: admin.news.edit, admin.articles.index, ...
     */
    public function adminRoute(string $name): string
    {
        return 'admin.'.$this->segment().'.'.$name;
    }

    /**
     * Public page URL of a post of this kind.
     */
    public function url(string $slug, ?string $locale = null): string
    {
        return route($this->siteRoute('show'), ['locale' => $locale ?? app()->getLocale(), 'newsPost' => $slug]);
    }

    /**
     * Public list URL of this kind.
     */
    public function listUrl(?string $locale = null): string
    {
        return route($this->siteRoute('index'), ['locale' => $locale ?? app()->getLocale()]);
    }

    /**
     * schema.org type of the post page.
     */
    public function schemaType(): string
    {
        return match ($this) {
            self::News => 'NewsArticle',
            self::Article => 'Article',
        };
    }

    /**
     * Public site text: site.news.{key} or site.articles.{key}.
     *
     * @param  array<string, mixed>  $replace
     */
    public function siteText(string $key, array $replace = []): string
    {
        return __('site.'.$this->segment().'.'.$key, $replace);
    }

    /**
     * Admin text: admin.articles.{key} when that key exists for this kind,
     * else the shared admin.news.{key} (field labels, hints, errors).
     *
     * @param  array<string, mixed>  $replace
     */
    public function adminText(string $key, array $replace = []): string
    {
        $own = 'admin.'.$this->segment().'.'.$key;

        return __(Lang::has($own) ? $own : 'admin.news.'.$key, $replace);
    }
}
