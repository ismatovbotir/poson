<?php

use App\Models\Category;
use App\Models\NewsPost;
use App\Models\Product;
use App\Support\Site\LlmsTxt;
use App\Support\Site\Navigation;
use App\Support\Site\NewsDirectory;
use App\Support\Site\Sitemap;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

// These tests cover the site open for indexing (plan 15: closed by default).
beforeEach(function () {
    openSearchIndexing();
});

/**
 * JSON-LD blocks of one @type.
 *
 * @return list<array<string, mixed>>
 */
function newsBlocks(string $html, string $type): array
{
    return array_values(array_filter(jsonLdBlocks($html), fn (array $block): bool => ($block['@type'] ?? null) === $type));
}

/**
 * hreflang => href of the page's alternate links.
 *
 * @return array<string, string>
 */
function newsHreflangs(string $html): array
{
    preg_match_all('#<link rel="alternate" hreflang="([^"]+)" href="([^"]+)">#', $html, $matches);

    return array_combine($matches[1], $matches[2]);
}

describe('visibility', function () {
    it('shows a published post in every translated locale', function () {
        NewsPost::factory()->create([
            'slug' => 'new-scanners',
            'title' => ['uz' => 'Yangi skanerlar', 'ru' => 'Новые сканеры', 'en' => 'New scanners'],
            'body' => ['uz' => '<p>Matn.</p>', 'ru' => '<p>Текст.</p>', 'en' => '<p>Text.</p>'],
        ]);

        $this->get('/uz/news/new-scanners')->assertOk()->assertSee('Yangi skanerlar')->assertSee('Matn.');
        $this->get('/ru/news/new-scanners')->assertOk()->assertSee('Новые сканеры')->assertSee('Текст.');
        $this->get('/en/news/new-scanners')->assertOk()->assertSee('New scanners');
    });

    it('hides drafts and scheduled posts everywhere until their time', function () {
        NewsPost::factory()->draft()->create(['slug' => 'draft-post', 'title' => ['uz' => 'Qoralama', 'ru' => 'Черновик', 'en' => 'Draft']]);
        $scheduled = NewsPost::factory()->scheduled(now()->addDay())->create(['slug' => 'soon-post', 'title' => ['uz' => 'Tez orada', 'ru' => 'Скоро', 'en' => 'Soon']]);

        $this->get('/uz/news/draft-post')->assertNotFound();
        $this->get('/uz/news/soon-post')->assertNotFound();
        $this->get('/uz/news')->assertOk()->assertDontSee('Qoralama')->assertDontSee('Tez orada')->assertSee('data-news-empty', false);
        expect($this->get('/uz/news/rss.xml')->getContent())->not->toContain('soon-post')->not->toContain('draft-post')
            ->and($this->get('/sitemap.xml')->getContent())->not->toContain('/news/')
            ->and(Navigation::news())->toBeNull();

        // No cron: the post appears once its time has come (the caches expire then too).
        $this->travelTo($scheduled->published_at->copy()->addMinute());

        $this->get('/uz/news/soon-post')->assertOk()->assertSee('Tez orada');
        $this->get('/uz/news')->assertSee('Tez orada');
        expect($this->get('/sitemap.xml')->getContent())->toContain('<loc>'.url('/uz/news/soon-post').'</loc>');
    });

    it('returns 404 in untranslated locales and leaves them out of lists, RSS, the sitemap and hreflang', function () {
        NewsPost::factory()->untranslated('en')->create([
            'slug' => 'local-news',
            'title' => ['uz' => 'Mahalliy xabar', 'ru' => 'Местная новость', 'en' => 'Local news'],
            'body' => ['uz' => '<p>Matn.</p>', 'ru' => '<p>Текст.</p>'],
        ]);

        $this->get('/en/news/local-news')->assertNotFound();
        $this->get('/en/news')->assertOk()->assertDontSee('Local news')->assertSee('data-news-empty', false);
        expect($this->get('/en/news/rss.xml')->getContent())->not->toContain('local-news');

        $html = $this->get('/ru/news/local-news')->assertOk()->getContent();

        expect(newsHreflangs($html))->toBe([
            'uz' => url('/uz/news/local-news'),
            'ru' => url('/ru/news/local-news'),
            'x-default' => url('/uz/news/local-news'),
        ])
            ->and($html)->toContain('<link rel="canonical" href="'.url('/ru/news/local-news').'">')
            // The language switcher sends en to the en news list, never to a 404.
            ->and($html)->toMatch('#href="'.preg_quote(url('/en/news'), '#').'"[^>]*data-locale-link="en"#');

        $xml = $this->get('/sitemap.xml')->getContent();

        expect($xml)->toContain('<loc>'.url('/ru/news/local-news').'</loc>')
            ->not->toContain(url('/en/news/local-news'))
            ->toContain('<loc>'.url('/uz/news').'</loc>')
            ->not->toContain('<loc>'.url('/en/news').'</loc>');
    });

    it('treats a title without a body as untranslated', function () {
        NewsPost::factory()->create([
            'slug' => 'half',
            'title' => ['uz' => 'Toʻliq', 'ru' => 'Только заголовок'],
            'body' => ['uz' => '<p>Matn.</p>', 'ru' => '<p> </p>'],
        ]);

        $this->get('/uz/news/half')->assertOk();
        $this->get('/ru/news/half')->assertNotFound();
    });
});

describe('list', function () {
    it('lists visible posts newest first with dates and paginates', function () {
        config(['site.news_per_page' => 2]);
        NewsPost::factory()->published(now()->subDays(3))->create(['title' => ['uz' => 'Uchinchi', 'ru' => 'R3', 'en' => 'E3']]);
        NewsPost::factory()->published(now()->subDays(2))->create(['title' => ['uz' => 'Ikkinchi', 'ru' => 'R2', 'en' => 'E2']]);
        NewsPost::factory()->published(now()->subDay())->create(['title' => ['uz' => 'Birinchi', 'ru' => 'R1', 'en' => 'E1']]);

        $this->get('/uz/news')->assertOk()
            ->assertSeeInOrder(['Birinchi', 'Ikkinchi'])
            ->assertDontSee('Uchinchi')
            ->assertSee('<time datetime="'.now()->subDay()->setTimezone('Asia/Tashkent')->toDateString().'"', false)
            ->assertSee('rel="alternate" type="application/rss+xml"', false);

        $this->get('/uz/news?page=2')->assertOk()->assertSee('Uchinchi');
        $this->get('/uz/news?page=9')->assertNotFound();
    });

    it('shows the cover once its WebP conversion exists', function () {
        Storage::fake('public');
        Storage::fake('local');
        $post = NewsPost::factory()->create(['slug' => 'with-cover']);
        $post->addMedia(UploadedFile::fake()->image('cover.jpg', 1600, 900))
            ->withCustomProperties(['alt' => ['uz' => 'Muqova', 'ru' => '', 'en' => 'Cover']])
            ->toMediaCollection('cover');

        expect($this->get('/en/news')->assertOk()->getContent())->toContain('alt="Cover"')->toMatch('#conversions/[^"]+\.webp#');

        $html = $this->get('/en/news/with-cover')->assertOk()->getContent();

        expect($html)->toContain('data-news-cover')
            ->toMatch('#<meta property="og:image" content="[^"]+conversions/[^"]+\.webp">#')
            ->toContain('<meta property="og:type" content="article">');
    });
});

describe('post page', function () {
    it('shows related published products and visible categories', function () {
        $category = Category::factory()->create(['slug' => 'scanners', 'name' => ['uz' => 'Skanerlar', 'en' => 'Scanners']]);
        $visible = Product::factory()->for($category)->create(['name' => ['uz' => 'Zebra DS2208', 'en' => 'Zebra DS2208']]);
        $draft = Product::factory()->for($category)->create(['is_active' => false, 'name' => ['uz' => 'Qoralama tovar', 'en' => 'Draft product']]);
        $hidden = Category::factory()->create(['is_active' => false, 'name' => ['uz' => 'Yashirin', 'en' => 'Hidden section']]);
        $post = NewsPost::factory()->create(['slug' => 'with-products']);
        $post->products()->attach([$visible->id, $draft->id]);
        $post->categories()->attach([$category->id, $hidden->id]);

        $this->get('/en/news/with-products')->assertOk()
            ->assertSee('data-news-products', false)
            ->assertSee('Zebra DS2208')
            ->assertDontSee('Draft product')
            ->assertSee('href="'.url('/en/catalog/scanners').'"', false)
            ->assertDontSee('Hidden section');
    });

    it('prints NewsArticle and BreadcrumbList JSON-LD', function () {
        $post = NewsPost::factory()->published(now()->subDays(2))->create([
            'slug' => 'json-ld',
            'title' => ['uz' => 'Sarlavha', 'en' => 'Headline'],
            'body' => ['uz' => '<p>Matn.</p>', 'en' => '<p>Body.</p>'],
            'excerpt' => ['en' => 'Short summary.'],
        ]);
        $post->forceFill(['content_updated_at' => now()->subDay()])->save();

        $html = $this->get('/en/news/json-ld')->assertOk()->getContent();
        $article = newsBlocks($html, 'NewsArticle')[0];

        expect($article['headline'])->toBe('Headline')
            ->and($article['url'])->toBe(url('/en/news/json-ld'))
            ->and($article['inLanguage'])->toBe('en')
            ->and($article['description'])->toBe('Short summary.')
            ->and($article['datePublished'])->toBe($post->published_at->toAtomString())
            ->and($article['dateModified'])->toBe(now()->subDay()->toAtomString())
            ->and($article['publisher']['@type'])->toBe('Organization')
            ->and($article['author']['@type'])->toBe('Organization')
            ->and(newsBlocks($html, 'BreadcrumbList')[0]['itemListElement'])->toBe([
                ['@type' => 'ListItem', 'position' => 1, 'name' => __('site.breadcrumbs.home', [], 'en'), 'item' => url('/en')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => __('site.news.title', [], 'en'), 'item' => url('/en/news')],
                ['@type' => 'ListItem', 'position' => 3, 'name' => 'Headline', 'item' => url('/en/news/json-ld')],
            ]);
    });

    it('renders meta title and description from the SEO fields', function () {
        NewsPost::factory()->create([
            'slug' => 'seo-post',
            'meta_title' => ['ru' => 'Новые ТСД — Poson'],
            'meta_description' => ['ru' => 'Поставка и настройка ТСД.'],
        ]);

        $this->get('/ru/news/seo-post')->assertOk()
            ->assertSee('<title>Новые ТСД — Poson</title>', false)
            ->assertSee('<meta name="description" content="Поставка и настройка ТСД.">', false);
    });

    it('sanitizes the body on output too', function () {
        $post = NewsPost::factory()->create(['slug' => 'raw-body']);
        NewsPost::query()->whereKey($post->id)->toBase()->update(['body' => json_encode(['uz' => '<p>Ok</p><script>alert(1)</script><img src=x onerror=alert(2)>'])]);

        $html = $this->get('/uz/news/raw-body')->assertOk()->getContent();

        expect($html)->toContain('<p>Ok</p>')->not->toContain('alert(1)')->not->toContain('onerror');
    });
});

describe('RSS', function () {
    it('is a valid RSS 2.0 feed per locale with only that locale\'s posts', function (string $locale) {
        NewsPost::factory()->create(['slug' => 'everywhere', 'title' => ['uz' => 'Hamma joyda & <b>', 'ru' => 'Везде', 'en' => 'Everywhere']]);
        NewsPost::factory()->untranslated('ru', 'en')->create(['slug' => 'uz-only']);
        NewsPost::factory()->scheduled()->create(['slug' => 'later']);

        $response = $this->get("/{$locale}/news/rss.xml")->assertOk();
        expect($response->headers->get('Content-Type'))->toStartWith('application/rss+xml');

        $xml = simplexml_load_string($response->getContent());

        expect($xml)->not->toBeFalse()
            ->and((string) $xml['version'])->toBe('2.0')
            ->and((string) $xml->channel->language)->toBe($locale)
            ->and((string) $xml->channel->link)->toBe(url("/{$locale}/news"))
            ->and((string) $xml->channel->title)->toBe(__('site.news.rss_title', [], $locale));

        $links = [];

        foreach ($xml->channel->item as $item) {
            $links[] = (string) $item->link;
        }

        expect($links)->toContain(url("/{$locale}/news/everywhere"))
            ->not->toContain(url("/{$locale}/news/later"));

        if ($locale === 'uz') {
            expect($links)->toContain(url('/uz/news/uz-only'));
        } else {
            expect($links)->not->toContain(url("/{$locale}/news/uz-only"));
        }

        foreach ($xml->channel->item as $item) {
            expect((string) $item->guid)->toBe((string) $item->link)
                ->and(strtotime((string) $item->pubDate))->not->toBeFalse();
        }
    })->with(['uz', 'ru', 'en']);

    it('builds links on APP_URL, never on the request host', function () {
        config(['app.url' => 'https://poson.uz']);
        NewsPost::factory()->create(['slug' => 'host-check']);

        $content = $this->get('http://evil.test/uz/news/rss.xml')->assertOk()->getContent();

        expect($content)->toContain('https://poson.uz/uz/news/host-check')->not->toContain('evil.test');
    });
});

describe('home and navigation', function () {
    it('shows the three latest posts on the home page, hidden when there are none', function () {
        $this->get('/uz')->assertOk()->assertDontSee('data-home-news', false)->assertDontSee('data-nav="news"', false);

        foreach (range(1, 4) as $day) {
            NewsPost::factory()->published(now()->subDays($day))->create(['title' => ['uz' => "Xabar {$day}", 'ru' => "Новость {$day}", 'en' => "News {$day}"]]);
        }

        $html = $this->get('/uz')->assertOk()->getContent();

        expect($html)->toContain('data-home-news')
            ->toContain('data-nav="news"')
            ->and(substr_count($html, 'data-news-card='))->toBe(3)
            ->and($html)->toContain('Xabar 1')->toContain('Xabar 3')->not->toContain('Xabar 4');
    });

    it('links the news list only in locales that have visible news', function () {
        NewsPost::factory()->untranslated('ru', 'en')->create();

        app()->setLocale('uz');
        expect(Navigation::news())->toBe(['label' => __('site.nav.news', [], 'uz'), 'url' => url('/uz/news')])
            ->and(collect(Navigation::customerPages())->pluck('key')->last())->toBe('news');

        app()->setLocale('en');
        expect(Navigation::news())->toBeNull()
            ->and(collect(Navigation::customerPages())->pluck('key'))->not->toContain('news');

        $this->get('/en')->assertOk()->assertDontSee('data-home-news', false);
    });

    it('caches the directory and flushes it with the sitemap and llms.txt on change', function () {
        $post = NewsPost::factory()->create(['slug' => 'cached']);
        expect(NewsDirectory::hasVisible('uz'))->toBeTrue();
        $this->get('/sitemap.xml');
        app(LlmsTxt::class)->text();

        expect(Cache::has(NewsDirectory::CACHE_KEY))->toBeTrue();

        $post->update(['status' => 'draft']);

        expect(Cache::has(NewsDirectory::CACHE_KEY))->toBeFalse()
            ->and(Cache::has(Sitemap::CACHE_KEY))->toBeFalse()
            ->and(Cache::has(LlmsTxt::CACHE_KEY))->toBeFalse()
            ->and(NewsDirectory::hasVisible('uz'))->toBeFalse();
    });

    it('lists up to three related news on the product page', function () {
        $product = Product::factory()->create();
        $other = Product::factory()->create();

        foreach (range(1, 4) as $day) {
            NewsPost::factory()->published(now()->subDays($day))->create(['title' => ['uz' => "Mahsulot xabari {$day}", 'ru' => 'R', 'en' => 'E']])->products()->attach($product);
        }

        NewsPost::factory()->create(['title' => ['uz' => 'Boshqa mahsulot', 'ru' => 'R', 'en' => 'E']])->products()->attach($other);

        $html = $this->get('/uz/product/'.$product->slug)->assertOk()->getContent();

        expect($html)->toContain('data-related-news')
            ->and(substr_count($html, 'data-news-card='))->toBe(3)
            ->and($html)->not->toContain('Boshqa mahsulot');

        $this->get('/uz/product/'.Product::factory()->create()->slug)->assertOk()->assertDontSee('data-related-news', false);
    });
});

describe('sitemap and llms.txt', function () {
    it('lists the news list and every visible post with hreflang among its locales and lastmod', function () {
        $post = NewsPost::factory()->create(['slug' => 'sitemap-post']);
        NewsPost::query()->whereKey($post->id)->toBase()->update(['content_updated_at' => '2026-03-05 10:00:00', 'published_at' => '2026-03-01 10:00:00']);
        NewsPost::factory()->draft()->create(['slug' => 'sitemap-draft']);
        NewsDirectory::flushAll();

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        expect($xml)->toContain('<loc>'.url('/en/news').'</loc>')
            ->toMatch('#<loc>'.preg_quote(url('/ru/news/sitemap-post'), '#').'</loc>\s*<lastmod>2026-03-05T#')
            ->toContain('<xhtml:link rel="alternate" hreflang="x-default" href="'.url('/uz/news/sitemap-post').'"/>')
            ->not->toContain('sitemap-draft');
    });

    it('lists the latest news in llms.txt with English titles, else the uz version', function () {
        config(['app.url' => 'https://poson.uz']);
        NewsPost::factory()->published(now()->subDays(2))->create(['slug' => 'english', 'title' => ['uz' => 'Inglizcha', 'ru' => 'R', 'en' => 'English post']]);
        NewsPost::factory()->untranslated('ru', 'en')->create(['slug' => 'uzbek', 'title' => ['uz' => 'Oʻzbekcha xabar']]);
        NewsPost::factory()->draft()->create(['slug' => 'secret']);

        $text = $this->get('/llms.txt')->assertOk()->getContent();

        expect($text)->toContain("\n## News\n\n")
            ->toContain('[English post](https://poson.uz/en/news/english)')
            ->toContain('[Oʻzbekcha xabar](https://poson.uz/uz/news/uzbek)')
            ->not->toContain('secret');
    });
});

describe('review follow-ups', function () {
    it('points x-default to the first translated locale for a ru + en post, like the sitemap', function () {
        NewsPost::factory()->untranslated('uz')->create([
            'slug' => 'ru-en-post',
            'title' => ['ru' => 'Только ru и en', 'en' => 'Only ru and en'],
            'body' => ['ru' => '<p>Текст.</p>', 'en' => '<p>Text.</p>'],
        ]);

        $this->get('/uz/news/ru-en-post')->assertNotFound();

        $html = $this->get('/en/news/ru-en-post')->assertOk()->getContent();

        expect(newsHreflangs($html))->toBe([
            'ru' => url('/ru/news/ru-en-post'),
            'en' => url('/en/news/ru-en-post'),
            'x-default' => url('/ru/news/ru-en-post'),
        ]);

        expect($this->get('/sitemap.xml')->getContent())
            ->toContain('<xhtml:link rel="alternate" hreflang="x-default" href="'.url('/ru/news/ru-en-post').'"/>')
            ->not->toContain(url('/uz/news/ru-en-post'));
    });

    it('uses the current locale alt for the cover, else the title, never the uz alt', function () {
        Storage::fake('public');
        Storage::fake('local');
        $post = NewsPost::factory()->create([
            'slug' => 'alt-post',
            'title' => ['uz' => 'Sarlavha', 'ru' => 'Заголовок', 'en' => 'Headline'],
        ]);
        $post->addMedia(UploadedFile::fake()->image('cover.jpg', 1600, 900))
            ->withCustomProperties(['alt' => ['uz' => 'Oʻzbekcha alt', 'ru' => '', 'en' => 'English alt']])
            ->toMediaCollection('cover');

        expect($this->get('/ru/news/alt-post')->getContent())->toContain('alt="Заголовок"')->not->toContain('Oʻzbekcha alt')
            ->and($this->get('/ru/news')->getContent())->toContain('alt="Заголовок"')->not->toContain('Oʻzbekcha alt')
            ->and($this->get('/en/news/alt-post')->getContent())->toContain('alt="English alt"')
            ->and($this->get('/uz/news')->getContent())->toContain('alt="Oʻzbekcha alt"');
    });

    it('dates the news list in the sitemap by the newest post of each locale', function () {
        NewsPost::factory()->published(now()->setDate(2026, 3, 1))->create();
        NewsPost::factory()->untranslated('ru', 'en')->published(now()->setDate(2026, 4, 2))->create();

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        expect($xml)
            ->toMatch('#<loc>'.preg_quote(url('/uz/news'), '#').'</loc>\s*<lastmod>2026-04-02T#')
            ->toMatch('#<loc>'.preg_quote(url('/ru/news'), '#').'</loc>\s*<lastmod>2026-03-01T#')
            ->toMatch('#<loc>'.preg_quote(url('/en/news'), '#').'</loc>\s*<lastmod>2026-03-01T#');
    });

    it('lists only the locales with visible news in the news list hreflang', function () {
        NewsPost::factory()->untranslated('en')->create();

        $html = $this->get('/uz/news')->assertOk()->getContent();

        expect(newsHreflangs($html))->toBe([
            'uz' => url('/uz/news'),
            'ru' => url('/ru/news'),
            'x-default' => url('/uz/news'),
        ])
            ->and($html)->toMatch('#href="'.preg_quote(url('/en/news'), '#').'"[^>]*data-locale-link="en"#');
    });
});

it('renders the news pages without banned wording', function (string $locale) {
    $post = NewsPost::factory()->create();

    expectNoBannedWording($this->get("/{$locale}/news")->assertOk()->getContent());
    expectNoBannedWording($this->get("/{$locale}/news/{$post->slug}")->assertOk()->getContent());
})->with(['uz', 'ru', 'en']);
