<?php

namespace App\Support\Site;

use App\Enums\NewsKind;
use App\Models\NewsPost;
use XMLWriter;

/**
 * RSS 2.0 feed of one locale (plan 07 7b, decision 2026-10-23: a feed per
 * locale) at /{locale}/news/rss.xml: the latest LIMIT news posts (never
 * articles, decision 2026-10-24) visible in that
 * locale with title, link, guid, pubDate (RFC 822) and description (the
 * excerpt or the start of the body, plain text; no HTML). Built on APP_URL
 * (CanonicalHost), like the sitemap, never on the request host. Not cached:
 * one small query per request (the route is throttled).
 */
class NewsFeed
{
    public const LIMIT = 20;

    public function xml(string $locale): string
    {
        return CanonicalHost::using(fn (): string => $this->build($locale));
    }

    public function build(string $locale): string
    {
        $posts = NewsPost::query()
            ->ofKind(NewsKind::News)
            ->visibleIn($locale)
            ->latestFirst()
            ->limit(self::LIMIT)
            ->get(['id', 'slug', 'title', 'excerpt', 'body', 'locales', 'published_at']);

        $self = route('site.news.rss', ['locale' => $locale]);

        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('rss');
        $xml->writeAttribute('version', '2.0');
        $xml->writeAttribute('xmlns:atom', 'http://www.w3.org/2005/Atom');
        $xml->startElement('channel');

        $xml->writeElement('title', __('site.news.rss_title', [], $locale));
        $xml->writeElement('link', route('site.news.index', ['locale' => $locale]));
        $xml->writeElement('description', __('site.news.seo_description', [], $locale));
        $xml->writeElement('language', $locale);

        $xml->startElement('atom:link');
        $xml->writeAttribute('href', $self);
        $xml->writeAttribute('rel', 'self');
        $xml->writeAttribute('type', 'application/rss+xml');
        $xml->endElement();

        if ($posts->isNotEmpty() && $posts->first()->published_at !== null) {
            $xml->writeElement('lastBuildDate', $posts->first()->published_at->toRfc2822String());
        }

        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            foreach ($posts as $post) {
                $url = route('site.news.show', ['locale' => $locale, 'newsPost' => $post->slug]);

                $xml->startElement('item');
                $xml->writeElement('title', (string) $post->getTranslation('title', $locale, false));
                $xml->writeElement('link', $url);
                $xml->startElement('guid');
                $xml->writeAttribute('isPermaLink', 'true');
                $xml->text($url);
                $xml->endElement();

                if ($post->published_at !== null) {
                    $xml->writeElement('pubDate', $post->published_at->toRfc2822String());
                }

                $xml->writeElement('description', NewsCards::excerpt($post));
                $xml->endElement();
            }
        } finally {
            app()->setLocale($previous);
        }

        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }
}
