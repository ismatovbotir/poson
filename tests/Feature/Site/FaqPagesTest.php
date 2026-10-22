<?php

use App\Models\Category;
use App\Models\FaqItem;
use App\Models\Product;
use App\Support\Catalog\CategoryTree;
use App\Support\Site\FaqContent;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\FaqSeeder;
use Illuminate\Database\Eloquent\Model;

/**
 * @return list<array<string, mixed>>
 */
function faqPageBlocks(string $html): array
{
    return array_values(array_filter(jsonLdBlocks($html), fn (array $block): bool => ($block['@type'] ?? null) === 'FAQPage'));
}

function faqItem(?Model $owner, string $question, string $answer = 'Answer.', array $attributes = []): FaqItem
{
    $factory = FaqItem::factory();

    return ($owner !== null ? $factory->owner($owner) : $factory)->create([
        'question' => ['uz' => $question.' (uz)', 'en' => $question],
        'answer' => ['uz' => $answer.' (uz)', 'en' => $answer],
        ...$attributes,
    ]);
}

describe('category listing', function () {
    it('shows the category FAQ as details/summary with a matching FAQPage', function () {
        $category = Category::factory()->create(['slug' => 'scanners']);
        faqItem($category, 'Which scanner fits a warehouse?', 'A rugged one.', ['sort' => 2]);
        faqItem($category, 'Do scanners work with 1C?', 'Yes, we connect them.', ['sort' => 1]);
        faqItem($category, 'Hidden question?', 'Hidden.', ['is_active' => false]);

        $html = $this->get('/en/catalog/scanners')->assertOk()->getContent();

        expect($html)->toContain('<details')
            ->toContain('<summary')
            ->toContain('data-faq')
            ->not->toContain('Hidden question?')
            ->and(strpos($html, 'Do scanners work with 1C?'))->toBeLessThan(strpos($html, 'Which scanner fits a warehouse?'));

        expect(faqPageBlocks($html))->toBe([[
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => [
                ['@type' => 'Question', 'name' => 'Do scanners work with 1C?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Yes, we connect them.']],
                ['@type' => 'Question', 'name' => 'Which scanner fits a warehouse?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'A rugged one.']],
            ],
        ]]);
    });

    it('falls back to the nearest ancestor with a FAQ, and falls back to uz text', function () {
        $root = Category::factory()->create(['slug' => 'scanners']);
        $middle = Category::factory()->child($root)->create(['slug' => 'handheld']);
        $leaf = Category::factory()->child($middle)->create(['slug' => 'wireless']);
        faqItem($root, 'Root question?');
        FaqItem::factory()->owner($middle)->create(['question' => ['uz' => 'Faqat oʻzbekcha savol?'], 'answer' => ['uz' => 'Javob.']]);

        $html = $this->get('/en/catalog/wireless')->assertOk()->getContent();

        // Inherited items are shown but not marked up: FAQPage belongs to the owner's page.
        expect($html)->toContain('Faqat oʻzbekcha savol?')
            ->toContain('data-faq')
            ->not->toContain('Root question?')
            ->and(faqPageBlocks($html))->toBe([]);

        $html = $this->get('/en/catalog/handheld')->assertOk()->getContent();
        expect(faqPageBlocks($html))->toHaveCount(1);

        $this->get('/en/catalog/scanners')->assertOk()->assertSeeText('Root question?');
        expect($leaf->exists)->toBeTrue();
    });

    it('marks up the FAQ only on the first page of a listing', function () {
        config(['site.per_page' => 1]);
        $category = Category::factory()->create(['slug' => 'scanners']);
        Product::factory()->count(2)->inCategory($category)->create();
        faqItem($category, 'Own question?');

        expect(faqPageBlocks($this->get('/en/catalog/scanners')->assertOk()->getContent()))->toHaveCount(1);

        $html = $this->get('/en/catalog/scanners?page=2')->assertOk()->getContent();
        expect($html)->toContain('Own question?')
            ->and(faqPageBlocks($html))->toBe([]);
    });

    it('shows no FAQ section and no FAQPage when there are no items', function () {
        Category::factory()->create(['slug' => 'printers']);
        faqItem(null, 'General question?');

        $html = $this->get('/en/catalog/printers')->assertOk()->getContent();

        expect($html)->not->toContain('data-faq')
            ->and(faqPageBlocks($html))->toBe([]);

        $html = $this->get('/en/catalog')->assertOk()->getContent();
        expect($html)->not->toContain('data-faq')->and(faqPageBlocks($html))->toBe([]);
    });
});

describe('product page', function () {
    it('shows the product FAQ, else the category one', function () {
        $category = Category::factory()->create();
        $own = Product::factory()->inCategory($category)->create(['slug' => 'with-own-faq']);
        Product::factory()->inCategory($category)->create(['slug' => 'without-faq']);
        faqItem($own, 'Product question?');
        faqItem($category, 'Category question?');

        $html = $this->get('/en/product/with-own-faq')->assertOk()->getContent();
        expect($html)->toContain('Product question?')->not->toContain('Category question?')
            ->and(faqPageBlocks($html)[0]['mainEntity'][0]['name'])->toBe('Product question?');

        $html = $this->get('/en/product/without-faq')->assertOk()->getContent();
        expect($html)->toContain('Category question?')
            ->and(faqPageBlocks($html))->toBe([]);
    });

    it('falls back to an ancestor category and omits FAQPage without items', function () {
        $root = Category::factory()->create();
        $leaf = Category::factory()->child($root)->create();
        Product::factory()->inCategory($leaf)->create(['slug' => 'leaf-product']);
        $bare = Product::factory()->create(['slug' => 'bare-product']);

        faqItem($root, 'Ancestor question?');

        $html = $this->get('/en/product/leaf-product')->assertOk()->getContent();
        expect($html)->toContain('Ancestor question?')->and(faqPageBlocks($html))->toBe([]);

        $html = $this->get('/en/product/bare-product')->assertOk()->getContent();
        expect($html)->not->toContain('data-faq')
            ->and(faqPageBlocks($html))->toBe([])
            ->and(collect(jsonLdBlocks($html))->pluck('@type')->all())->toBe(['Product', 'BreadcrumbList']);
        expect($bare->exists)->toBeTrue();
    });
});

describe('home page', function () {
    it('shows at most six active general items before the lead form', function () {
        foreach (range(1, 8) as $number) {
            faqItem(null, "General question {$number}?", 'Answer.', ['sort' => $number]);
        }
        faqItem(null, 'Inactive general?', 'Answer.', ['sort' => 0, 'is_active' => false]);
        faqItem(Category::factory()->create(), 'Category only?');

        $html = $this->get('/en')->assertOk()->getContent();

        expect($html)->toContain('General question 6?')
            ->not->toContain('General question 7?')
            ->not->toContain('Inactive general?')
            ->not->toContain('Category only?')
            ->and(strpos($html, 'data-faq'))->toBeLessThan(strpos($html, 'id="lead"'))
            ->and(faqPageBlocks($html)[0]['mainEntity'])->toHaveCount(6);
    });

    it('has no FAQ section or FAQPage without general items', function () {
        $html = $this->get('/ru')->assertOk()->getContent();

        expect($html)->not->toContain('data-faq')->and(faqPageBlocks($html))->toBe([]);
    });
});

it('renders answers escaped with line breaks only', function () {
    faqItem(null, 'Is <b>HTML</b> shown?', "<script>alert('x')</script>First line\nSecond line");

    $html = $this->get('/en')->assertOk()->getContent();

    expect($html)->toContain('Is &lt;b&gt;HTML&lt;/b&gt; shown?')
        ->toContain('&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt;First line<br>'."\nSecond line")
        ->not->toContain("<script>alert('x')")
        ->and(faqPageBlocks($html)[0]['mainEntity'][0]['acceptedAnswer']['text'])->toBe("<script>alert('x')</script>First line\nSecond line");
});

it('skips stored items with banned wording', function () {
    faqItem(null, 'Is it cheap?', 'Yes.');
    faqItem(null, 'Is there a warranty?', 'Yes.');
    app()->setLocale('en');

    expect(array_column(app(FaqContent::class)->general(), 'question'))->toBe(['Is there a warranty?']);
});

it('shows the seeded FAQ without banned wording on every page type and locale', function (string $locale) {
    $this->seed([CatalogSeeder::class, FaqSeeder::class]);
    $category = Category::query()->where('slug', 'scanners')->sole();
    $leaf = Category::query()->where('parent_id', $category->id)->firstOrFail();
    Product::factory()->inCategory($leaf)->create(['slug' => 'seeded-faq-product']);

    $pages = [
        "/{$locale}" => 1,
        "/{$locale}/catalog/scanners" => 1,
        "/{$locale}/catalog/{$leaf->slug}" => 0,
        "/{$locale}/product/seeded-faq-product" => 0,
    ];

    foreach ($pages as $url => $markup) {
        $html = $this->get($url)->assertOk()->getContent();

        expect($html)->toContain('data-faq')
            ->and(faqPageBlocks($html))->toHaveCount($markup);

        expectNoBannedWording(strip_tags($html));
    }
})->with(['uz', 'ru', 'en']);

it('loads the FAQ with one query whatever the number of items', function () {
    $root = Category::factory()->create();
    $leaf = Category::factory()->child($root)->create();
    $product = Product::factory()->inCategory($leaf)->create();
    faqItem($root, 'One?');

    $faq = app(FaqContent::class);
    // Warm the cached category tree (shared by every page).
    CategoryTree::nodes();
    $one = [countQueries(fn () => $faq->forProduct($product)), countQueries(fn () => $faq->forCategory($leaf->id)), countQueries(fn () => $faq->general())];

    foreach (range(1, 10) as $number) {
        faqItem($root, "More {$number}?");
        faqItem(null, "General {$number}?");
    }

    $many = [countQueries(fn () => $faq->forProduct($product)), countQueries(fn () => $faq->forCategory($leaf->id)), countQueries(fn () => $faq->general())];

    expect($many)->toBe($one)->and(max($many))->toBeLessThanOrEqual(1);
});
