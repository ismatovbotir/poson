<?php

use App\Models\Vacancy;
use App\Support\Site\Sitemap;
use App\Support\Site\StructuredData;
use Illuminate\Support\Facades\Cache;

/**
 * Plan 31 phase C: JobPosting / CollectionPage JSON-LD, meta and the sitemap.
 */
function ldBlocks(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

    return array_map(fn (string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR), $matches[1]);
}

it('prints a valid JobPosting without baseSalary and a breadcrumb on the vacancy page', function () {
    $vacancy = Vacancy::factory()->published()->create(['valid_through' => '2030-05-01']);

    $blocks = collect(ldBlocks($this->get($vacancy->url('uz'))->assertOk()->getContent()));
    $job = $blocks->firstWhere('@type', 'JobPosting');

    expect($job)->not->toBeNull()
        ->and($job['title'])->toBe($vacancy->getTranslation('title', 'uz'))
        ->and($job['description'])->toContain('<p>Birinchi band.</p>', '<ul><li>Tajriba</li></ul>')
        ->and($job['datePosted'])->toBe($vacancy->published_at->toAtomString())
        ->and($job['validThrough'])->toBe('2030-05-01T23:59:59+05:00')
        ->and($job['employmentType'])->toBe('FULL_TIME')
        ->and($job['hiringOrganization']['@type'])->toBe('Organization')
        ->and($job['jobLocation']['address']['addressCountry'])->toBe('UZ')
        ->and($job['jobLocation']['address']['addressRegion'])->toBe(__('enums.region.'.$vacancy->location->value, [], 'en'))
        ->and($job['directApply'])->toBeTrue()
        ->and($job['identifier'])->toMatchArray(['@type' => 'PropertyValue', 'value' => (string) $vacancy->id])
        ->and($job)->not->toHaveKey('baseSalary')
        ->and($blocks->pluck('@type')->all())->toContain('BreadcrumbList');
});

it('leaves validThrough out when no end date is set', function () {
    $vacancy = Vacancy::factory()->published()->create();

    $job = collect(ldBlocks($this->get($vacancy->url('en'))->getContent()))->firstWhere('@type', 'JobPosting');

    expect($job)->not->toHaveKey('validThrough');
});

it('keeps a script-closing title and description inert in JSON-LD and meta', function () {
    $evil = '</script><script>alert(1)</script>';
    $vacancy = Vacancy::factory()->published()->create([
        'title' => ['uz' => "Titul {$evil}", 'ru' => 'Р', 'en' => 'E'],
        'description' => ['uz' => "Matn {$evil} & 'x'", 'ru' => 'Р', 'en' => 'E'],
        'requirements' => ['uz' => [$evil], 'ru' => ['Р'], 'en' => ['E']],
        'meta_title' => ['uz' => $evil, 'ru' => null, 'en' => null],
    ]);

    $html = $this->get($vacancy->url('uz'))->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)')
        ->and(substr_count($html, '</script><script>alert'))->toBe(0);

    $job = collect(ldBlocks($html))->firstWhere('@type', 'JobPosting');
    expect($job['description'])->toContain('&lt;/script&gt;&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($job['description'])->not->toContain('<script>');
});

it('encodes quotes and apostrophes as unicode escapes for script blocks', function () {
    $json = StructuredData::toScript(['a' => "O'Brien \"x\" </script> &"]);

    expect($json)->not->toContain("'")->and($json)->not->toContain('<')->and($json)->not->toContain('&')
        ->and(json_decode($json, true)['a'])->toBe("O'Brien \"x\" </script> &");
});

it('prints canonical, hreflang for the three slugs and CollectionPage on the list', function () {
    $vacancy = Vacancy::factory()->published()->create();

    $html = $this->get($vacancy->url('ru'))->getContent();

    foreach (['uz', 'ru', 'en'] as $locale) {
        expect($html)->toContain('hreflang="'.$locale.'"')->toContain($vacancy->url($locale));
    }
    expect($html)->toContain('hreflang="x-default"')->toContain('rel="canonical" href="'.$vacancy->url('ru').'"');

    $types = collect(ldBlocks($this->get('/uz/careers')->assertOk()->getContent()))->pluck('@type')->all();
    expect($types)->toContain('CollectionPage')->toContain('BreadcrumbList');
});

it('keeps the open application page noindex', function () {
    $this->get('/uz/careers/apply/sales')->assertOk()->assertSee('noindex', false);
});

it('lists the careers list and three locale URLs per published vacancy, never drafts or expired', function () {
    $published = Vacancy::factory()->published()->create();
    $draft = Vacancy::factory()->create();
    $expired = Vacancy::factory()->expired()->create();
    Cache::flush();

    $generated = app(Sitemap::class)->generate();
    $locs = collect($generated['urls'])->where('type', 'careers')->pluck('loc')->all();

    foreach (['uz', 'ru', 'en'] as $locale) {
        expect($locs)->toContain($published->url($locale))->toContain(route('site.careers.index', ['locale' => $locale]))
            ->not->toContain($draft->url($locale))->not->toContain($expired->url($locale));
    }
    expect($generated['counts']['careers'])->toBe(6)
        ->and($generated['xml'])->not->toContain('/apply');
});

it('lists no careers URL when no vacancy is public', function () {
    Vacancy::factory()->create();

    expect(app(Sitemap::class)->generate()['counts']['careers'])->toBe(0);
});

it('flushes the sitemap when a vacancy is saved or deleted', function () {
    $vacancy = Vacancy::factory()->published()->create();
    Cache::flush();
    app(Sitemap::class)->xml();
    expect(Cache::has(Sitemap::CACHE_KEY))->toBeTrue();

    $vacancy->update(['sort' => 5]);
    expect(Cache::has(Sitemap::CACHE_KEY))->toBeFalse();

    app(Sitemap::class)->xml();
    $vacancy->delete();
    expect(Cache::has(Sitemap::CACHE_KEY))->toBeFalse();
});
