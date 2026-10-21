<?php

use App\Models\Category;
use App\Models\Product;
use App\Support\Site\CanonicalHost;
use App\Support\Site\RobotsGroup;
use App\Support\Site\RobotsTxt;
use App\Support\Site\Sitemap;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * @return array<string, list<array{hreflang: string, href: string}>> loc => alternates
 */
function sitemapEntries(string $xml): array
{
    $document = new SimpleXMLElement($xml);
    $entries = [];

    foreach ($document->url as $url) {
        $alternates = [];

        foreach ($url->children('http://www.w3.org/1999/xhtml')->link as $link) {
            $alternates[] = ['hreflang' => (string) $link->attributes()->hreflang, 'href' => (string) $link->attributes()->href];
        }

        $entries[(string) $url->loc] = $alternates;
    }

    return $entries;
}

it('lists the home page, catalog, visible categories and published products in every locale', function () {
    $root = Category::factory()->create(['slug' => 'scanners']);
    $child = Category::factory()->child($root)->create(['slug' => 'handheld']);
    $hidden = Category::factory()->inactive()->create(['slug' => 'hidden-category']);
    Product::factory()->inCategory($child)->create(['slug' => 'zebra-ds2208']);
    Product::factory()->inactive()->create(['slug' => 'draft-product']);
    Product::factory()->create(['slug' => 'trashed-product'])->delete();
    Product::factory()->inCategory($hidden)->create(['slug' => 'hidden-product']);

    $response = $this->get('/sitemap.xml')->assertOk();
    $xml = $response->getContent();
    $entries = sitemapEntries($xml);

    expect($response->headers->get('Content-Type'))->toStartWith('application/xml')
        ->and(array_keys($entries))->toContain(
            url('/uz'), url('/ru'), url('/en'),
            url('/uz/catalog'), url('/ru/catalog'), url('/en/catalog'),
            url('/uz/catalog/scanners'), url('/ru/catalog/handheld'), url('/en/catalog/handheld'),
            url('/uz/product/zebra-ds2208'), url('/ru/product/zebra-ds2208'), url('/en/product/zebra-ds2208'),
        )
        ->and($xml)->not->toContain('hidden-category')
        ->not->toContain('draft-product')
        ->not->toContain('trashed-product')
        ->not->toContain('hidden-product')
        ->and($entries[url('/ru/product/zebra-ds2208')])->toBe([
            ['hreflang' => 'uz', 'href' => url('/uz/product/zebra-ds2208')],
            ['hreflang' => 'ru', 'href' => url('/ru/product/zebra-ds2208')],
            ['hreflang' => 'en', 'href' => url('/en/product/zebra-ds2208')],
            ['hreflang' => 'x-default', 'href' => url('/uz/product/zebra-ds2208')],
        ])
        ->and($xml)->toMatch('#<loc>'.preg_quote(url('/en/product/zebra-ds2208'), '#').'</loc><lastmod>\d{4}-\d{2}-\d{2}T#');
});

it('caches the sitemap and flushes it on catalog saves', function () {
    $product = Product::factory()->create(['slug' => 'first-product']);
    $this->get('/sitemap.xml')->assertOk();

    expect(Cache::has(Sitemap::CACHE_KEY))->toBeTrue();

    $product->update(['slug' => 'renamed-product']);

    expect(Cache::has(Sitemap::CACHE_KEY))->toBeFalse()
        ->and($this->get('/sitemap.xml')->getContent())->toContain('renamed-product')->not->toContain('first-product');

    Category::factory()->create(['slug' => 'new-category']);
    expect($this->get('/sitemap.xml')->getContent())->toContain('new-category');

    $product->delete();
    expect($this->get('/sitemap.xml')->getContent())->not->toContain('renamed-product');
});

it('serves robots.txt with the base rules and the sitemap', function () {
    $response = $this->get('/robots.txt')->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/plain')
        ->and($response->getContent())->toBe(implode("\n", [
            'User-agent: *',
            'Allow: /livewire-*/livewire.js',
            'Allow: /livewire-*/js/',
            'Disallow: /admin',
            'Disallow: /livewire',
            'Disallow: /*/search',
            'Disallow: /*?*sort=',
            'Disallow: /*?*f[',
            'Disallow: /*?*f%5B',
            '',
            'Sitemap: '.url('/sitemap.xml'),
        ])."\n")
        ->and(file_exists(public_path('robots.txt')))->toBeFalse();
});

it('lets later plans add crawler groups to robots.txt', function () {
    $robots = (new RobotsTxt)->withGroup(new RobotsGroup(['GPTBot'], disallow: ['/'], allow: ['/en/catalog']));

    expect($robots->render())->toContain("User-agent: *\nAllow: /livewire-*/livewire.js")
        ->toContain("User-agent: GPTBot\nAllow: /en/catalog\nDisallow: /");
});

it('builds sitemap and robots URLs on APP_URL, whatever the Host header', function () {
    // Trusted hosts are static on the Symfony request and may leak from other tests; Laravel skips the check in tests.
    SymfonyRequest::setTrustedHosts([]);
    config(['app.url' => 'https://poson.uz']);
    Product::factory()->create(['slug' => 'zebra-ds2208']);

    $xml = $this->get('http://evil.example/sitemap.xml')->assertOk()->getContent();
    $robots = $this->get('http://evil.example/robots.txt')->assertOk()->getContent();

    expect($xml)->toContain('<loc>https://poson.uz/ru/product/zebra-ds2208</loc>')
        ->toContain('href="https://poson.uz/uz/product/zebra-ds2208"')
        ->not->toContain('evil.example')
        ->and($robots)->toContain('Sitemap: https://poson.uz/sitemap.xml')
        ->not->toContain('evil.example')
        // The forced root is restored for the rest of the request cycle.
        ->and(url('/ru'))->not->toStartWith('https://poson.uz');
});

it('trusts only the APP_URL host and its www twin', function () {
    config(['app.url' => 'https://poson.uz']);

    expect(CanonicalHost::trustedHosts())->toBe(['^poson\\.uz$', '^www\\.poson\\.uz$']);
});
