<?php

use App\Models\BotHit;
use App\Models\Quote;
use App\Models\Setting;
use App\Support\CompanySettings;
use Illuminate\Support\Facades\Cache;

const INDEXING_GPTBOT_UA = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot';

describe('fail closed', function () {
    it('is closed on a fresh database, without a settings row', function () {
        expect(app(CompanySettings::class)->isIndexingOpen())->toBeFalse()
            ->and(Setting::query()->where('key', CompanySettings::INDEXING_KEY)->exists())->toBeFalse();
    });

    it('is closed for any stored value but boolean true', function (mixed $value) {
        Setting::query()->create(['key' => CompanySettings::INDEXING_KEY, 'group' => 'seo', 'value' => $value]);
        CompanySettings::flush();

        expect(app(CompanySettings::class)->isIndexingOpen())->toBeFalse();
    })->with([
        'false' => [false],
        'string true' => ['true'],
        'one' => [1],
        'array' => [['open' => true]],
        'null' => [null],
    ]);

    it('is closed when the cache store fails', function () {
        openSearchIndexing();
        Cache::shouldReceive('remember')->andThrow(new RuntimeException('cache down'));
        app()->forgetScopedInstances();

        expect(app(CompanySettings::class)->isIndexingOpen())->toBeFalse();
    });

    it('opens only for a stored boolean true', function () {
        openSearchIndexing();

        expect(app(CompanySettings::class)->isIndexingOpen())->toBeTrue();
    });
});

describe('closed', function () {
    it('marks public pages noindex, nofollow in the meta tag and the header', function (string $uri) {
        $this->get($uri)
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    })->with(['/uz', '/ru/catalog', '/en/news', '/uz/brands']);

    it('keeps a page’s own stronger robots value', function () {
        $this->get('/ru/search?q=zebra')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertDontSee('noindex,follow', false);
    });

    it('closes robots.txt to every crawler, without a sitemap or AI bot groups', function () {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertContent("User-agent: *\nDisallow: /\n");
    });

    it('hides sitemap.xml and llms.txt, even when a body is cached', function (string $uri, string $key) {
        Cache::forever($key, 'cached body');

        $this->get($uri)->assertNotFound()->assertDontSee('cached body');
    })->with([
        'sitemap' => ['/sitemap.xml', 'site.sitemap'],
        'llms.txt' => ['/llms.txt', 'site.llms-txt'],
    ]);

    it('keeps the stronger noarchive header of the customer quote page', function () {
        $quote = Quote::factory()->sent()->create();

        $this->get(route('quotes.public', $quote->ulid))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    });

    it('keeps recording AI crawler visits', function () {
        config(['analytics.enabled' => true]);

        $this->withHeaders(['User-Agent' => INDEXING_GPTBOT_UA])->get('/robots.txt')->assertOk();
        $this->withHeaders(['User-Agent' => INDEXING_GPTBOT_UA])->get('/uz')->assertOk();

        expect(BotHit::query()->count())->toBe(2);
    });
});

describe('open', function () {
    beforeEach(function () {
        openSearchIndexing();
    });

    it('adds no robots header or meta to public pages', function (string $uri) {
        $this->get($uri)
            ->assertOk()
            ->assertHeaderMissing('X-Robots-Tag')
            ->assertDontSee('<meta name="robots"', false);
    })->with(['/uz', '/ru/catalog']);

    it('serves the normal robots.txt, sitemap.xml and llms.txt', function () {
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /admin')->assertSee('Sitemap: ');
        $this->get('/sitemap.xml')->assertOk();
        $this->get('/llms.txt')->assertOk();
    });

    it('never weakens the noarchive header of the customer quote page', function () {
        $quote = Quote::factory()->sent()->create();

        $this->get(route('quotes.public', $quote->ulid))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    });
});
