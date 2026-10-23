<?php

use App\Models\FaqItem;
use App\Models\Page;
use App\Models\Setting;
use App\Support\CompanySettings;
use App\Support\DateFormat;
use App\Support\Site\LlmsTxt;
use App\Support\Site\Navigation;
use App\Support\Site\PageDirectory;
use App\Support\Site\Sitemap;
use Database\Seeders\PagesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * JSON-LD blocks of one @type.
 *
 * @return list<array<string, mixed>>
 */
function pageBlocks(string $html, string $type): array
{
    return array_values(array_filter(jsonLdBlocks($html), fn (array $block): bool => ($block['@type'] ?? null) === $type));
}

describe('page', function () {
    it('shows an active page in every locale, falling back to uz', function () {
        Page::factory()->create([
            'slug' => 'careers',
            'title' => ['uz' => 'Ish oʻrinlari', 'ru' => 'Вакансии'],
            'body' => ['uz' => '<p>Bizda ishlang.</p>'],
        ]);

        $this->get('/ru/page/careers')->assertOk()->assertSee('Вакансии')->assertSee('Bizda ishlang.');
        $this->get('/en/page/careers')->assertOk()->assertSee('Ish oʻrinlari');
    });

    it('returns 404 for inactive and unknown pages', function () {
        Page::factory()->system('offer')->inactive()->create();

        $this->get('/uz/page/offer')->assertNotFound();
        $this->get('/uz/page/no-such-page')->assertNotFound();
    });

    it('renders meta title, description and canonical', function () {
        Page::factory()->create([
            'slug' => 'b2b-help',
            'title' => ['uz' => 'Biznes uchun'],
            'meta_title' => ['en' => 'For business — Poson'],
            'meta_description' => ['en' => 'Equipment selection and setup for companies.'],
        ]);

        $this->get('/en/page/b2b-help')->assertOk()
            ->assertSee('<title>For business — Poson</title>', false)
            ->assertSee('<meta name="description" content="Equipment selection and setup for companies.">', false)
            ->assertSee('<link rel="canonical" href="'.url('/en/page/b2b-help').'">', false);
    });

    it('shows the legal template with its updated date', function () {
        Page::factory()->system('privacy')->needsReview()->create();

        $this->get('/ru/page/privacy')->assertOk()
            ->assertSee('data-page-template="legal"', false)
            ->assertSee('data-page-updated', false)
            ->assertDontSee('data-contacts', false);
    });

    it('dates a legal page by its last content change, else by its last save', function () {
        $page = Page::factory()->system('privacy')->create();
        Page::query()->whereKey($page->id)->toBase()->update(['content_updated_at' => '2026-03-05 10:00:00', 'updated_at' => '2026-09-20 10:00:00']);

        $this->get('/ru/page/privacy')->assertOk()
            ->assertSee(DateFormat::iso(Carbon::parse('2026-03-05'), 'D MMMM YYYY', 'ru'))
            ->assertDontSee(DateFormat::iso(Carbon::parse('2026-09-20'), 'D MMMM YYYY', 'ru'));

        Page::query()->whereKey($page->id)->toBase()->update(['content_updated_at' => null]);

        $this->get('/ru/page/privacy')->assertOk()
            ->assertSee(DateFormat::iso(Carbon::parse('2026-09-20'), 'D MMMM YYYY', 'ru'));
    });

    it('uses the content date as dateModified for every template', function (string $slug) {
        $page = Page::factory()->system($slug)->create();
        Page::query()->whereKey($page->id)->toBase()->update(['content_updated_at' => '2026-03-05 10:00:00', 'updated_at' => '2026-09-20 10:00:00']);

        $html = $this->get("/en/page/{$slug}")->assertOk()->getContent();
        $webPage = collect(jsonLdBlocks($html))->first(fn (array $block): bool => isset($block['dateModified']));

        expect($webPage)->not->toBeNull()
            ->and($webPage['dateModified'])->toStartWith('2026-03-05');
    })->with(['about', 'contacts', 'privacy']);

    it('shows no updated date outside the legal template', function () {
        Page::factory()->system('about')->create(['content_updated_at' => now()]);

        $this->get('/uz/page/about')->assertOk()->assertDontSee('data-page-updated', false);
    });

    it('shows the contacts template with company contacts, the map facade and the lead form', function () {
        Setting::factory()->create(['key' => 'email', 'value' => 'sales@poson.uz']);
        Setting::factory()->create(['key' => 'geo', 'value' => ['lat' => '40.528', 'lng' => '70.942']]);
        CompanySettings::flush();
        Page::factory()->system('contacts')->create();

        $html = $this->get('/uz/page/contacts')->assertOk()->getContent();

        expect($html)->toContain('data-contacts')
            ->toContain('sales@poson.uz')
            ->toContain('data-show-map')
            ->toContain('wire:name="site.lead-form"')
            // The map iframe exists only inside an Alpine template: nothing loads before the click.
            ->toMatch('#<template x-if="map">\s*<iframe src="https://yandex\.uz/map-widget/v1/\?ll=70\.942,40\.528#')
            ->and(substr_count($html, '<iframe'))->toBe(1);
    });

    it('shows only the filled-in requisites on the contacts page', function () {
        Setting::factory()->create(['key' => 'legal', 'value' => [
            'legal_name' => ['uz' => '"POSON SAVDO" MChJ', 'ru' => 'ООО «POSON SAVDO»', 'en' => ''],
            'inn' => '305123456',
            'legal_address' => ['uz' => '', 'ru' => '', 'en' => ''],
        ]]);
        CompanySettings::flush();
        Page::factory()->system('contacts')->create();

        $this->get('/ru/page/contacts')->assertOk()
            ->assertSee('data-requisites', false)
            ->assertSee(__('site.pages.contacts.requisites', [], 'ru'))
            ->assertSee('ООО «POSON SAVDO»')
            ->assertSee('data-requisite="legal_name"', false)
            ->assertSee(__('site.pages.contacts.inn', [], 'ru'))
            ->assertSee('305123456')
            ->assertDontSee('data-requisite="legal_address"', false)
            ->assertDontSee(__('site.pages.contacts.legal_address', [], 'ru'));

        // en has no legal name of its own: localized() falls back to uz.
        $this->get('/en/page/contacts')->assertOk()
            ->assertSee(__('site.pages.contacts.inn', [], 'en'))
            ->assertSee('305123456')
            ->assertSee(e('"POSON SAVDO" MChJ'), false);
    });

    it('hides the requisites block when nothing is filled in', function () {
        Page::factory()->system('contacts')->create();

        $this->get('/uz/page/contacts')->assertOk()
            ->assertSee('data-contacts', false)
            ->assertDontSee('data-requisites', false)
            ->assertDontSee(__('site.pages.contacts.requisites', [], 'uz'));
    });

    it('shows the cover image once its WebP conversion exists', function () {
        Storage::fake('public');
        Storage::fake('local');
        $page = Page::factory()->create(['slug' => 'about-team']);
        $page->addMedia(UploadedFile::fake()->image('cover.jpg', 1600, 900))
            ->withCustomProperties(['alt' => ['uz' => 'Jamoa', 'ru' => '', 'en' => 'Team']])
            ->toMediaCollection('cover');

        $html = $this->get('/en/page/about-team')->assertOk()->getContent();

        expect($html)->toContain('data-page-cover')
            ->toContain('alt="Team"')
            ->toMatch('#<meta property="og:image" content="[^"]+conversions/[^"]+\.webp">#');
    });
});

describe('JSON-LD', function () {
    it('prints WebPage and BreadcrumbList for a default page', function () {
        Page::factory()->create(['slug' => 'warranty', 'title' => ['uz' => 'Kafolat', 'en' => 'Warranty']]);

        $html = $this->get('/en/page/warranty')->assertOk()->getContent();
        $page = pageBlocks($html, 'WebPage')[0];

        expect($page['name'])->toBe('Warranty')
            ->and($page['url'])->toBe(url('/en/page/warranty'))
            ->and($page['inLanguage'])->toBe('en')
            ->and(pageBlocks($html, 'BreadcrumbList')[0]['itemListElement'])->toBe([
                ['@type' => 'ListItem', 'position' => 1, 'name' => __('site.breadcrumbs.home', [], 'en'), 'item' => url('/en')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Warranty', 'item' => url('/en/page/warranty')],
            ])
            ->and(pageBlocks($html, 'FAQPage'))->toBe([]);
    });

    it('uses AboutPage for about and ContactPage for contacts', function () {
        Page::factory()->system('about')->create();
        Page::factory()->system('contacts')->create();

        expect(pageBlocks($this->get('/uz/page/about')->getContent(), 'AboutPage'))->toHaveCount(1)
            ->and(pageBlocks($this->get('/uz/page/contacts')->getContent(), 'ContactPage'))->toHaveCount(1);
    });

    it('shows the page FAQ with a FAQPage for its own active items', function () {
        $page = Page::factory()->system('delivery-payment')->create();
        FaqItem::factory()->owner($page)->create(['question' => ['uz' => 'Savol B?', 'en' => 'Question B?'], 'answer' => ['uz' => 'B', 'en' => 'Answer B.'], 'sort' => 2]);
        FaqItem::factory()->owner($page)->create(['question' => ['uz' => 'Savol A?', 'en' => 'Question A?'], 'answer' => ['uz' => 'A', 'en' => 'Answer A.'], 'sort' => 1]);
        FaqItem::factory()->owner($page)->inactive()->create(['question' => ['uz' => 'Yashirin?', 'en' => 'Hidden?']]);
        FaqItem::factory()->create(['question' => ['uz' => 'Umumiy?', 'en' => 'General?']]);

        $html = $this->get('/en/page/delivery-payment')->assertOk()->getContent();

        expect($html)->toContain('data-faq')
            ->not->toContain('Hidden?')
            ->not->toContain('General?')
            ->and(pageBlocks($html, 'FAQPage')[0]['mainEntity'])->toBe([
                ['@type' => 'Question', 'name' => 'Question A?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Answer A.']],
                ['@type' => 'Question', 'name' => 'Question B?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Answer B.']],
            ]);
    });
});

describe('navigation', function () {
    it('links active pages and falls back to the home anchors for inactive ones', function () {
        Page::factory()->system('delivery-payment')->create();
        Page::factory()->system('privacy')->create();
        Page::factory()->system('offer')->inactive()->create();
        app()->setLocale('ru');

        expect(Navigation::page('delivery-payment'))->toBe(url('/ru/page/delivery-payment'))
            ->and(Navigation::page('privacy'))->toBe(url('/ru/page/privacy'))
            ->and(Navigation::page('offer'))->toBe(url('/ru').'#contacts')
            ->and(Navigation::page('warranty-service'))->toBe(url('/ru').'#contacts')
            ->and(Navigation::page('about'))->toBe(url('/ru'));
    });

    it('puts About first in the footer and follows page changes', function () {
        $about = Page::factory()->system('about')->create();

        $html = $this->get('/uz')->assertOk()->getContent();

        expect($html)->toContain('href="'.url('/uz/page/about').'"')
            ->and(collect(Navigation::customerPages())->pluck('key')->first())->toBe('about');

        $about->update(['is_active' => false]);

        expect(PageDirectory::isActive('about'))->toBeFalse()
            ->and($this->get('/uz')->getContent())->not->toContain('href="'.url('/uz/page/about').'"');
    });

    it('resolves every page link of the seeded site', function (string $locale) {
        $this->seed(PagesSeeder::class);
        app()->setLocale($locale);

        foreach (array_keys(Page::SYSTEM) as $slug) {
            $url = Navigation::page($slug);

            if (str_contains($url, '/page/')) {
                $this->get($url)->assertOk();
            }
        }
    })->with(['uz', 'ru', 'en']);
});

describe('sitemap and llms.txt', function () {
    it('lists active pages in the sitemap, legal ones included', function () {
        Page::factory()->system('about')->create();
        Page::factory()->system('privacy')->create();
        Page::factory()->system('offer')->inactive()->create();

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        expect($xml)->toContain('<loc>'.url('/uz/page/about').'</loc>')
            ->toContain('<loc>'.url('/en/page/privacy').'</loc>')
            ->not->toContain('/page/offer');
    });

    it('uses the content date as the sitemap lastmod of a page, else its last save', function () {
        $about = Page::factory()->system('about')->create();
        $privacy = Page::factory()->system('privacy')->create();
        Page::query()->whereKey($about->id)->toBase()->update(['content_updated_at' => '2026-03-05 10:00:00', 'updated_at' => '2026-09-20 10:00:00']);
        Page::query()->whereKey($privacy->id)->toBase()->update(['content_updated_at' => null, 'updated_at' => '2026-08-11 10:00:00']);
        PageDirectory::flushAll();

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        expect($xml)
            ->toMatch('#<loc>'.preg_quote(url('/uz/page/about'), '#').'</loc>.*?<lastmod>2026-03-05T#s')
            ->toMatch('#<loc>'.preg_quote(url('/uz/page/privacy'), '#').'</loc>.*?<lastmod>2026-08-11T#s')
            ->not->toContain('<lastmod>2026-09-20T');
    });

    it('lists active non-legal pages in llms.txt with English titles', function () {
        config(['app.url' => 'https://poson.uz']);
        Page::factory()->system('about')->create(['title' => ['uz' => 'Biz haqimizda', 'en' => 'About us']]);
        Page::factory()->system('privacy')->create();
        Page::factory()->create(['slug' => 'hidden-page', 'is_active' => false]);

        $text = $this->get('/llms.txt')->assertOk()->getContent();

        expect($text)->toContain("\n## Pages\n\n- [About us](https://poson.uz/en/page/about)")
            ->not->toContain('/page/privacy')
            ->not->toContain('hidden-page');
    });

    it('flushes the sitemap and llms.txt when a page changes', function () {
        $page = Page::factory()->create(['slug' => 'careers']);
        $this->get('/sitemap.xml');
        app(LlmsTxt::class)->text();

        $page->update(['is_active' => false]);

        expect(cache()->has(Sitemap::CACHE_KEY))->toBeFalse()
            ->and(cache()->has(LlmsTxt::CACHE_KEY))->toBeFalse()
            ->and($this->get('/sitemap.xml')->getContent())->not->toContain('/page/careers');
    });
});

it('renders the seeded pages without banned wording', function (string $locale) {
    $this->seed(PagesSeeder::class);

    foreach (Page::query()->active()->pluck('slug') as $slug) {
        expectNoBannedWording($this->get("/{$locale}/page/{$slug}")->assertOk()->getContent());
    }
})->with(['uz', 'ru', 'en']);
