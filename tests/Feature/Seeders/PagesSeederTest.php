<?php

use App\Enums\PageTemplate;
use App\Models\FaqItem;
use App\Models\Page;
use App\Support\Site\PageDirectory;
use Database\Seeders\PagesSeeder;

/**
 * @return array<string, array<string, mixed>>
 */
function pagesData(): array
{
    return require database_path('seeders/data/pages.php');
}

it('seeds every system page with its template, flags and FAQ', function () {
    $this->seed(PagesSeeder::class);

    $data = pagesData();

    expect(Page::query()->pluck('slug')->sort()->values()->all())->toBe(collect(array_keys(Page::SYSTEM))->sort()->values()->all());

    foreach (Page::query()->with('faqItems')->get() as $page) {
        expect($page->is_system)->toBeTrue()
            ->and($page->template)->toBe(Page::SYSTEM[$page->slug])
            ->and($page->getTranslation('title', 'uz'))->toBe($data[$page->slug]['title']['uz'])
            ->and($page->faqItems)->toHaveCount(count($data[$page->slug]['faq']))
            ->and($page->faqItems->every(fn (FaqItem $item): bool => $item->is_active))->toBeTrue();
    }

    $privacy = Page::query()->where('slug', 'privacy')->sole();
    $offer = Page::query()->where('slug', 'offer')->sole();

    expect($privacy->is_active)->toBeTrue()->and($privacy->needs_review)->toBeTrue()->and($privacy->template)->toBe(PageTemplate::Legal)
        ->and($offer->is_active)->toBeFalse()->and($offer->needs_review)->toBeTrue()
        ->and(Page::query()->whereNotIn('slug', ['offer'])->where('is_active', false)->exists())->toBeFalse()
        ->and(PageDirectory::isActive('privacy'))->toBeTrue()
        ->and(PageDirectory::isActive('offer'))->toBeFalse();
});

it('sets the content date of every created page', function () {
    $this->freezeSecond();
    $this->seed(PagesSeeder::class);

    expect(Page::query()->whereNull('content_updated_at')->exists())->toBeFalse()
        ->and(Page::query()->where('slug', 'privacy')->sole()->content_updated_at->equalTo(now()))->toBeTrue();
});

it('publishes the privacy body without the draft line or lawyer placeholders', function () {
    $this->seed(PagesSeeder::class);

    $privacy = Page::query()->where('slug', 'privacy')->sole();

    foreach (config('app.supported_locales') as $locale) {
        $body = (string) $privacy->getTranslation('body', $locale, false);

        expect($body)->not->toBe('')
            ->not->toContain('[')
            ->not->toContain(']')
            ->not->toMatch('/Qoralama|Черновик|Draft|Yurist|Юрист|Lawyer/iu');
    }
});

it('stores sanitized bodies', function () {
    $this->seed(PagesSeeder::class);

    foreach (Page::query()->get() as $page) {
        foreach ($page->getTranslations('body') as $body) {
            expect($body)->not->toContain('<script')->not->toContain('style=')->not->toContain('onclick');
        }
    }
});

it('is idempotent and never overwrites admin changes', function () {
    $this->seed(PagesSeeder::class);
    $counts = [Page::query()->count(), FaqItem::query()->count()];

    $about = Page::query()->where('slug', 'about')->sole();
    $about->storeTranslations('title', ['uz' => 'Kompaniya haqida'])->save();
    Page::query()->where('slug', 'offer')->update(['is_active' => true, 'needs_review' => false]);
    Page::query()->where('slug', 'b2b')->sole()->update(['is_active' => false]);
    $warranty = Page::query()->where('slug', 'warranty-service')->sole();
    FaqItem::query()->ownedBy($warranty)->delete();

    $this->seed(PagesSeeder::class);

    expect(Page::query()->count())->toBe($counts[0])
        ->and($about->fresh()->getTranslation('title', 'uz'))->toBe('Kompaniya haqida')
        ->and(Page::query()->where('slug', 'offer')->sole()->is_active)->toBeTrue()
        ->and(Page::query()->where('slug', 'b2b')->sole()->is_active)->toBeFalse()
        ->and(FaqItem::query()->ownedBy($warranty)->count())->toBe(0)
        ->and(FaqItem::query()->count())->toBe($counts[1] - count(pagesData()['warranty-service']['faq']));
});

it('recreates only a missing page', function () {
    $this->seed(PagesSeeder::class);
    Page::query()->where('slug', 'about')->sole()->delete();

    $this->seed(PagesSeeder::class);

    expect(Page::query()->where('slug', 'about')->exists())->toBeTrue()
        ->and(Page::query()->count())->toBe(count(Page::SYSTEM));
});

it('skips pages with banned wording', function () {
    $seeder = new PagesSeeder;
    $seeder->run([
        'sale-page' => [
            'template' => 'default',
            'needs_review' => false,
            'title' => ['uz' => 'Chegirmalar', 'ru' => 'Скидки', 'en' => 'Sale'],
            'meta_title' => [], 'meta_description' => [],
            'body' => ['uz' => '<p>Matn</p>'],
            'faq' => [],
        ],
        'careers' => [
            'template' => 'default',
            'needs_review' => false,
            'title' => ['uz' => 'Ish oʻrinlari'],
            'body' => ['uz' => '<p>Matn</p>'],
            'faq' => [['question' => ['uz' => 'Savol?'], 'answer' => ['uz' => 'Javob.']]],
        ],
    ]);

    expect(Page::query()->pluck('slug')->all())->toBe(['careers'])
        ->and(Page::query()->sole()->is_system)->toBeFalse()
        ->and(FaqItem::query()->sole()->faqable_type)->toBe('page')
        ->and($seeder->warnings)->toHaveCount(1);
});

it('contains no banned wording in the seed data', function () {
    expectNoBannedWording(json_encode(pagesData(), JSON_UNESCAPED_UNICODE));
});
