<?php

use App\Models\Category;
use App\Models\NewsPost;
use App\Models\Product;
use App\Support\Site\Navigation;
use App\Support\Site\NewsCards;
use App\Support\Site\NewsDirectory;

/**
 * JSON-LD @types of a page.
 *
 * @return list<string>
 */
function articleLdTypes(string $html): array
{
    return array_values(array_map(fn (array $block): string => (string) ($block['@type'] ?? ''), jsonLdBlocks($html)));
}

describe('kind separation', function () {
    it('shows an article on /articles and 404s it on /news, and the other way round', function () {
        NewsPost::factory()->article()->create(['slug' => 'how-to-choose-a-scanner', 'title' => ['uz' => 'Skanerni tanlash', 'ru' => 'Как выбрать сканер', 'en' => 'Choosing a scanner']]);
        NewsPost::factory()->create(['slug' => 'service-saturdays', 'title' => ['uz' => 'Shanba kunlari', 'ru' => 'По субботам', 'en' => 'Saturdays']]);

        $this->get('/uz/articles/how-to-choose-a-scanner')->assertOk()->assertSee('Skanerni tanlash');
        $this->get('/uz/news/how-to-choose-a-scanner')->assertNotFound();
        $this->get('/uz/news/service-saturdays')->assertOk();
        $this->get('/uz/articles/service-saturdays')->assertNotFound();

        $this->get('/uz/articles')->assertOk()->assertSee('Skanerni tanlash')->assertDontSee('Shanba kunlari')->assertDontSee('data-news-rss', false);
        $this->get('/uz/news')->assertOk()->assertSee('Shanba kunlari')->assertDontSee('Skanerni tanlash');
    });

    it('applies the same visibility and locale rules to articles', function () {
        NewsPost::factory()->article()->draft()->create(['slug' => 'draft-guide']);
        NewsPost::factory()->article()->scheduled()->create(['slug' => 'soon-guide']);
        NewsPost::factory()->article()->untranslated('en')->create(['slug' => 'uz-ru-guide']);

        $this->get('/uz/articles/draft-guide')->assertNotFound();
        $this->get('/uz/articles/soon-guide')->assertNotFound();
        $this->get('/en/articles/uz-ru-guide')->assertNotFound();
        $this->get('/ru/articles/uz-ru-guide')->assertOk();
        $this->get('/en/articles')->assertOk()->assertSee('data-news-empty', false)->assertSee('noindex,follow', false);
    });

    it('keeps RSS and the home "Latest news" news-only', function () {
        NewsPost::factory()->article()->create(['slug' => 'label-guide', 'title' => ['uz' => 'Yorliq qoʻllanmasi', 'ru' => 'Гид по этикеткам', 'en' => 'Label guide']]);

        expect($this->get('/uz/news/rss.xml')->getContent())->not->toContain('label-guide');
        $this->get('/uz')->assertOk()->assertDontSee('data-home-news', false)->assertDontSee('Yorliq qoʻllanmasi');

        NewsPost::factory()->create(['slug' => 'company-news']);

        expect($this->get('/uz/news/rss.xml')->getContent())->toContain('company-news')->not->toContain('label-guide');
        $this->get('/uz')->assertSee('data-home-news', false)->assertDontSee('Yorliq qoʻllanmasi');
    });
});

describe('seo', function () {
    it('marks articles up as Article and news as NewsArticle, with breadcrumbs', function () {
        NewsPost::factory()->article()->create(['slug' => 'guide']);
        NewsPost::factory()->create(['slug' => 'story']);

        $article = $this->get('/en/articles/guide')->assertOk()->getContent();
        $news = $this->get('/en/news/story')->assertOk()->getContent();

        expect(articleLdTypes($article))->toContain('Article', 'BreadcrumbList')->not->toContain('NewsArticle')
            ->and(articleLdTypes($news))->toContain('NewsArticle')
            ->and($article)->toContain('href="'.url('/en/articles').'"');
    });

    it('lists only translated locales in hreflang and sends the switcher to the articles list', function () {
        NewsPost::factory()->article()->untranslated('en')->create(['slug' => 'local-guide']);

        $html = $this->get('/ru/articles/local-guide')->assertOk()->getContent();

        preg_match_all('#<link rel="alternate" hreflang="([^"]+)" href="([^"]+)">#', $html, $matches);

        expect(array_combine($matches[1], $matches[2]))->toBe([
            'uz' => url('/uz/articles/local-guide'),
            'ru' => url('/ru/articles/local-guide'),
            'x-default' => url('/uz/articles/local-guide'),
        ])
            ->and($html)->toContain('<link rel="canonical" href="'.url('/ru/articles/local-guide').'">')
            ->and($html)->toMatch('#href="'.preg_quote(url('/en/articles'), '#').'"[^>]*data-locale-link="en"#');
    });

    it('puts the articles list and visible articles in the sitemap and llms.txt', function () {
        NewsPost::factory()->article()->create(['slug' => 'visible-guide', 'title' => ['uz' => 'Qoʻllanma', 'ru' => 'Гид', 'en' => 'Scanner guide']]);
        NewsPost::factory()->article()->draft()->create(['slug' => 'draft-guide']);

        $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();

        expect($sitemap)->toContain('<loc>'.url('/uz/articles').'</loc>')
            ->toContain('<loc>'.url('/en/articles/visible-guide').'</loc>')
            ->not->toContain('draft-guide')
            ->not->toContain('/news/visible-guide');

        $llms = $this->get('/llms.txt')->assertOk()->getContent();

        expect($llms)->toContain("## Articles\n\n- [Scanner guide](".url('/en/articles/visible-guide').')')
            ->not->toContain('draft-guide')
            ->not->toContain('## News');
    });
});

describe('navigation', function () {
    it('shows the Articles item only while the locale has a visible article', function () {
        $this->get('/uz')->assertOk()->assertDontSee('data-nav="articles"', false);
        expect(Navigation::articles())->toBeNull();

        NewsPost::factory()->article()->untranslated('en')->create();

        $this->get('/uz')->assertOk()->assertSee('data-nav="articles"', false)->assertSee('href="'.url('/uz/articles').'"', false);
        $this->get('/en')->assertOk()->assertDontSee('data-nav="articles"', false);
    });

    it('hides the Articles item while only news is visible', function () {
        NewsPost::factory()->create();
        NewsPost::factory()->article()->draft()->create();

        $this->get('/uz')->assertOk()->assertSee('data-nav="news"', false)->assertDontSee('data-nav="articles"', false);
        expect(array_column(Navigation::customerPages(), 'key'))->toContain('news')->not->toContain('articles');
    });

    it('adds Articles to the footer "For customers" column after News', function () {
        NewsPost::factory()->create();
        NewsPost::factory()->article()->create();
        app()->setLocale('uz');
        NewsDirectory::flush();

        $keys = array_column(Navigation::customerPages(), 'key');

        expect(array_slice($keys, -2))->toBe(['news', 'articles']);
    });
});

describe('filters', function () {
    it('filters the list by top-level category and noindexes the filtered list', function () {
        $scanners = Category::factory()->create(['slug' => 'scanners', 'name' => ['uz' => 'Skanerlar', 'ru' => 'Сканеры', 'en' => 'Scanners']]);
        $wired = Category::factory()->child($scanners)->create(['slug' => 'wired-scanners']);
        $printers = Category::factory()->create(['slug' => 'printers', 'name' => ['uz' => 'Printerlar', 'ru' => 'Принтеры', 'en' => 'Printers']]);

        NewsPost::factory()->article()->create(['title' => ['uz' => 'Skaner maqolasi', 'ru' => 'Р', 'en' => 'E']])->categories()->attach($wired);
        NewsPost::factory()->article()->create(['title' => ['uz' => 'Printer maqolasi', 'ru' => 'Р', 'en' => 'E']])->categories()->attach($printers);

        $this->get('/uz/articles')->assertOk()
            ->assertSee('data-article-filters', false)
            ->assertSee('?category=scanners', false)
            ->assertSee('Printer maqolasi')
            ->assertDontSee('noindex', false);

        $this->get('/uz/articles?category=scanners')->assertOk()
            ->assertSee('Skaner maqolasi')
            ->assertDontSee('Printer maqolasi')
            ->assertSee('noindex,follow', false);
    });
});

describe('filter edge cases', function () {
    beforeEach(function () {
        $this->scanners = Category::factory()->create(['slug' => 'scanners']);
        $this->wired = Category::factory()->child($this->scanners)->create(['slug' => 'wired-scanners']);
        $this->printers = Category::factory()->create(['slug' => 'printers']);
    });

    it('ignores an unknown or a subcategory filter: full list, indexable', function (string $slug) {
        NewsPost::factory()->article()->create(['title' => ['uz' => 'Skaner maqolasi', 'ru' => 'Р', 'en' => 'E']])->categories()->attach($this->wired);
        NewsPost::factory()->article()->create(['title' => ['uz' => 'Printer maqolasi', 'ru' => 'Р', 'en' => 'E']])->categories()->attach($this->printers);

        $this->get('/uz/articles?category='.$slug)->assertOk()
            ->assertSee('Skaner maqolasi')
            ->assertSee('Printer maqolasi')
            ->assertDontSee('noindex', false);
    })->with(['unknown' => 'no-such-topic', 'subcategory' => 'wired-scanners']);

    it('keeps the category filter in the pager links', function () {
        config(['site.news_per_page' => 2]);

        foreach (range(1, 3) as $i) {
            NewsPost::factory()->article()->published(now()->subDays($i))->create()->categories()->attach($this->scanners);
        }

        NewsPost::factory()->article()->create()->categories()->attach($this->printers);

        $html = $this->get('/uz/articles?category=scanners')->assertOk()->getContent();

        expect($html)->toContain('category=scanners&amp;page=2');

        $this->get('/uz/articles?category=scanners&page=2')->assertOk()->assertSee('data-news-card', false);
        $this->get('/uz/articles?category=scanners&page=3')->assertNotFound();
    });
});

describe('related articles', function () {
    it('shows up to three useful articles on a category page in one query', function () {
        $parent = Category::factory()->create(['slug' => 'printers']);
        $category = Category::factory()->child($parent)->create(['slug' => 'label-printers']);
        $product = Product::factory()->for($category)->create();

        NewsPost::factory()->article()->create(['title' => ['uz' => 'Birinchi maqola', 'ru' => 'Р', 'en' => 'E']])->categories()->attach($category);

        $this->get('/uz/catalog/label-printers')->assertOk()->assertSee('data-category-articles', false)->assertSee('Birinchi maqola');
        $this->get('/uz/product/'.$product->slug)->assertOk()->assertSee('data-related-articles', false)->assertSee('Birinchi maqola');

        app()->setLocale('uz');
        NewsDirectory::hasVisible('uz');
        $one = countQueries(fn () => NewsCards::articlesFor($category->id));

        foreach (range(1, 4) as $i) {
            NewsPost::factory()->article()->create()->categories()->attach($i % 2 ? $parent : $category);
        }

        NewsDirectory::hasVisible('uz');
        $many = countQueries(fn () => expect(NewsCards::articlesFor($category->id))->toHaveCount(3));

        expect($many)->toBe($one)->toBeLessThanOrEqual(2);

        // A news post linked to the category never shows here.
        NewsPost::factory()->create(['title' => ['uz' => 'Yangilik matni', 'ru' => 'Р', 'en' => 'E']])->categories()->attach($category);
        $this->get('/uz/catalog/label-printers')->assertDontSee('Yangilik matni');
    });

    it('shows no block and runs no query while the locale has no articles', function () {
        $category = Category::factory()->create(['slug' => 'scanners']);
        NewsPost::factory()->article()->draft()->create()->categories()->attach($category);

        $this->get('/uz/catalog/scanners')->assertOk()->assertDontSee('data-category-articles', false);

        app()->setLocale('uz');
        NewsDirectory::hasVisible('uz');
        expect(countQueries(fn () => NewsCards::articlesFor($category->id)))->toBe(0);
    });

    it('keeps product "related news" news-only', function () {
        $product = Product::factory()->create();
        NewsPost::factory()->article()->create(['title' => ['uz' => 'Maqola nomi', 'ru' => 'Р', 'en' => 'E']])->products()->attach($product);

        $this->get('/uz/product/'.$product->slug)->assertOk()->assertDontSee('data-related-news', false);
    });
});
