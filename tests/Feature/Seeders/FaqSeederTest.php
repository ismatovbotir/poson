<?php

use App\Models\Category;
use App\Models\FaqItem;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\FaqSeeder;

/**
 * @return list<array<string, mixed>>
 */
function faqDataGroups(): array
{
    return require database_path('seeders/data/faq.php');
}

it('seeds the general FAQ and the top-level category FAQ from the data file', function () {
    $this->seed(CatalogSeeder::class);
    $seeder = app(FaqSeeder::class);
    $seeder->setContainer(app())->__invoke();

    $groups = faqDataGroups();
    $general = collect($groups)->firstWhere('scope', 'general');

    expect($seeder->warnings)->toBe([])
        ->and(FaqItem::query()->count())->toBe(collect($groups)->sum(fn (array $group) => count($group['items'])))
        ->and(FaqItem::query()->ownedBy(null)->count())->toBe(count($general['items']));

    foreach (collect($groups)->where('scope', 'category') as $group) {
        $category = Category::query()->where('slug', $group['slug'])->whereNull('parent_id')->sole();

        expect($category->faqItems()->pluck('sort')->all())->toBe(range(1, count($group['items'])))
            ->and($category->faqItems()->first()->getTranslation('question', 'ru'))->toBe($group['items'][0]['question']['ru']);
    }
});

it('is idempotent and never brings back reworded or deleted items', function () {
    $this->seed([CatalogSeeder::class, FaqSeeder::class]);
    $count = FaqItem::query()->count();

    [$reworded, $deleted] = FaqItem::query()->ownedBy(null)->ordered()->take(2)->get()->all();
    $originalQuestion = $reworded->getTranslation('question', 'uz');
    $deletedQuestion = $deleted->getTranslation('question', 'uz');

    $reworded->storeTranslations('question', ['uz' => 'Admin qayta yozgan savol?'])->save();
    $deleted->delete();

    $this->seed(FaqSeeder::class);
    $this->seed(FaqSeeder::class);

    $questions = FaqItem::query()->get()->map(fn (FaqItem $item) => $item->getTranslation('question', 'uz'));

    expect(FaqItem::query()->count())->toBe($count - 1)
        ->and($questions)->toContain('Admin qayta yozgan savol?')
        ->not->toContain($originalQuestion)
        ->not->toContain($deletedQuestion);
});

it('seeds an owner that has no items yet, and leaves owners with items alone', function () {
    $this->seed([CatalogSeeder::class, FaqSeeder::class]);

    $scanners = Category::query()->where('slug', 'scanners')->sole();
    $scannersCount = $scanners->faqItems()->count();
    $printers = Category::query()->where('slug', 'label-printers')->sole();
    $printers->faqItems()->delete();
    FaqItem::factory()->forCategory($scanners)->create();

    $this->seed(FaqSeeder::class);

    $printersGroup = collect(faqDataGroups())->firstWhere('slug', 'label-printers');

    expect($printers->faqItems()->count())->toBe(count($printersGroup['items']))
        ->and($scanners->faqItems()->count())->toBe($scannersCount + 1);
});

it('skips unknown category slugs with a warning', function () {
    $seeder = app(FaqSeeder::class);
    $seeder->setContainer(app())->__invoke();

    expect($seeder->warnings)->not->toBeEmpty()
        ->and(FaqItem::query()->whereNotNull('faqable_type')->count())->toBe(0)
        ->and(FaqItem::query()->ownedBy(null)->count())->toBeGreaterThan(0);
});

it('runs from DatabaseSeeder', function () {
    $this->seed(DatabaseSeeder::class);

    expect(FaqItem::query()->count())->toBeGreaterThan(0);
});

it('has plain, brand-safe, Kokand-based copy in every locale', function () {
    $texts = collect(faqDataGroups())->pluck('items')->flatten(1)
        ->flatMap(fn (array $item) => [...array_values($item['question']), ...array_values($item['answer'])]);

    foreach ($texts as $text) {
        expect($text)->toBe(strip_tags($text))
            ->and(mb_strlen($text))->toBeLessThanOrEqual(FaqItem::ANSWER_MAX);
    }

    expect($texts->implode(' '))->not->toMatch('/Tashkent|Ташкент|Toshkent/u');
    expectNoBannedWording($texts->implode(' '));

    foreach (collect(faqDataGroups())->pluck('items')->flatten(1) as $item) {
        expect(array_keys($item['question']))->toEqualCanonicalizing(['uz', 'ru', 'en'])
            ->and(array_keys($item['answer']))->toEqualCanonicalizing(['uz', 'ru', 'en'])
            ->and(mb_strlen($item['question']['uz']))->toBeLessThanOrEqual(FaqItem::QUESTION_MAX);
    }
});
