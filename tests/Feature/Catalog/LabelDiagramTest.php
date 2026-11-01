<?php

use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\RollType;
use App\Support\Catalog\LabelDiagram;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    openSearchIndexing();
});

/**
 * A published label product in the "thermal-labels" category with the given size.
 *
 * @param  list<RollType>|null  $rollTypes
 * @param  array<string, mixed>  $attributes
 */
function diagramProduct(float|int|string|null $width = 100, float|int|string|null $height = 150, ?array $rollTypes = null, array $attributes = []): Product
{
    $category = Category::query()->where('slug', 'thermal-labels')->first() ?? Category::factory()->create(['slug' => 'thermal-labels']);
    $product = Product::factory()->perLabel(12)->inCategory($category)->withRollTypes($rollTypes ?? [RollType::query()->where('labels_per_roll', 500)->first() ?? RollType::factory()->size(500, '40.0')->create()])->create([
        'slug' => 'diagram-'.fake()->unique()->numerify('####'),
        ...$attributes,
    ]);

    foreach (['label_width' => $width, 'label_height' => $height] as $code => $value) {
        $attribute = Attribute::query()->where('code', $code)->first() ?? Attribute::factory()->number('mm')->create(['code' => $code]);
        $row = ProductAttributeValue::factory()->for($product)->create(['attribute_id' => $attribute->id]);

        DB::table('product_attribute_values')->where('id', $row->id)->update(['value_number' => $value, 'value_text' => null]);
    }

    return $product;
}

describe('geometry', function () {
    it('accepts only sane sizes', function (mixed $value, ?float $expected) {
        expect(LabelDiagram::millimetres($value))->toBe($expected);
    })->with([
        ['100.0000', 100.0],
        ['25.4000', 25.4],
        [57, 57.0],
        [5, 5.0],
        [300, 300.0],
        ['abc', null],
        ['1e999', null],
        ['1e2', null],
        [-5, null],
        ['-5', null],
        [0, null],
        ['0.0000', null],
        [301, null],
        ['300.01', null],
        [4.99, null],
        [NAN, null],
        [INF, null],
        [null, null],
        [[100], null],
        [true, null],
    ]);

    it('formats numbers without trailing zeros', function () {
        expect(LabelDiagram::format(57.0))->toBe('57')
            ->and(LabelDiagram::format(25.4))->toBe('25.4')
            ->and(LabelDiagram::make(100.0, 150.0)->size)->toBe('100 × 150')
            ->and(LabelDiagram::make(57.0, 25.4)->size)->toBe('57 × 25.4');
    });

    it('keeps the middle label, dimensions and texts inside the viewBox and runs the strip off the edges, also for extreme ratios', function (float $width, float $height, string $variant) {
        $d = LabelDiagram::make($width, $height, null, 1, $variant);
        $base = LabelDiagram::BASE_SIZE;
        $drawH = LabelDiagram::DRAW_H;
        $inside = fn (float $v, float $max): bool => is_finite($v) && $v >= 0.0 && $v <= $max;

        expect($d->unit())->toBe($d->viewSize / $base)->and($d->labelW)->toBeGreaterThan(0.0)->and($d->labelH)->toBeGreaterThan(0.0)
            ->and($d->labels)->not->toBeEmpty();

        // Middle label fully visible, centred vertically; neighbours reach the edges (clipped).
        expect($inside($d->middleY, $drawH))->toBeTrue()->and($inside($d->middleY + $d->labelH, $drawH))->toBeTrue()
            ->and($d->middleY)->toEqualWithDelta($drawH - $d->middleY - $d->labelH, 0.02)
            ->and(min(array_column($d->labels, 'y')))->toBeLessThan($d->middleY)
            ->and(max(array_column($d->labels, 'y')))->toBeGreaterThan($d->middleY);

        foreach ($d->labels as $label) {
            expect($inside($label['x'], $base))->toBeTrue()->and($inside($label['x'] + $d->labelW, $base))->toBeTrue()
                ->and($label['y'] + $d->labelH)->toBeGreaterThan(0.0)->and($label['y'])->toBeLessThan($drawH);
        }

        expect($d->linerX)->toBeGreaterThanOrEqual(0.0)->and($d->linerX + $d->linerW + 21.0)->toBeLessThanOrEqual($base)
            ->and($d->linerW)->toBeGreaterThan($d->labelW)
            ->and($inside($d->widthTextY, $base))->toBeTrue()->and($inside($d->heightTextX, $base))->toBeTrue()
            ->and($inside($d->heightTextY, $drawH))->toBeTrue()->and($inside($d->sizeTextY, $base))->toBeTrue()->and($inside($d->captionY, $base))->toBeTrue()
            ->and($d->sizeTextY)->toBeGreaterThan($drawH);

        preg_match_all('/-?\d+(\.\d+)?/', $d->extensionPath.$d->dimensionLinePath.$d->arrowPath, $numbers);
        foreach ($numbers[0] as $number) {
            expect((float) $number)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual($base);
        }
    })->with([[100, 150], [300, 5], [5, 300], [5, 5], [300, 300], [57, 25.4], [100, 100]])->with(['card', 'gallery']);

    it('draws a square label for 100 x 100 and a taller one for 100 x 150', function () {
        $square = LabelDiagram::make(100.0, 100.0);

        expect($square->labelW)->toBe($square->labelH)
            ->and(LabelDiagram::make(100.0, 150.0)->labelH)->toBeGreaterThan(LabelDiagram::make(100.0, 150.0)->labelW);
    });

    it('follows the W:H proportions when they fit', function () {
        $d = LabelDiagram::make(100.0, 50.0);

        expect($d->labelW / $d->labelH)->toEqualWithDelta(2.0, 0.05);
    });

    it('falls back to card for an unknown variant and only knows the closed map', function (mixed $variant) {
        expect(LabelDiagram::make(100.0, 150.0, null, 1, (string) $variant)->variant)->toBe('card')
            ->and(array_keys(LabelDiagram::VARIANTS))->toBe(['card', 'gallery']);
    })->with(['', 'GALLERY', 'huge', '../../etc', '<script>']);

    it('renders an unknown context as card with no injected markup', function () {
        $diagram = LabelDiagram::make(100.0, 150.0, null, 3);
        $html = Blade::render('<x-catalog.label-diagram :diagram="$diagram" :context="$context" />', ['diagram' => $diagram, 'context' => '"><script>x</script>']);

        expect($html)->toContain('data-variant="card"')->toContain('viewBox="0 0 200 200"')->not->toContain('<script')
            ->and(Blade::render('<x-catalog.label-diagram :diagram="$diagram" context="gallery" />', ['diagram' => $diagram]))->toContain('viewBox="0 0 300 300"');
    });
});

describe('rendering', function () {
    it('draws the diagram on the card and the product page of a label product without an image', function () {
        $product = diagramProduct(100, 150);

        $listing = $this->get('/en/catalog/thermal-labels')->assertOk()->getContent();
        $page = $this->get("/en/product/{$product->slug}")->assertOk()->getContent();

        foreach ([$listing, $page] as $html) {
            expect($html)->toContain('data-label-diagram')
                ->toContain('role="img"')
                ->toContain('Label size 100 × 150 mm')
                ->toContain('500 pcs · core 40 mm');

            preg_match('#<svg[^>]*data-label-diagram.*?</svg>#s', $html, $svg);
            expect($svg[0] ?? '')->not->toContain('<script')->not->toContain('style=')->not->toContain('foreignObject')->not->toContain('<image')->not->toContain('href')->not->toContain('<use');
        }

        expect($listing)->toContain("label-diagram-{$product->id}-card")
            ->and($page)->toContain("label-diagram-{$product->id}-gallery");
        expectNoBannedWording($page);
    });

    it('is localized in uz, ru and en', function () {
        $product = diagramProduct(57, 25.4);

        expect($this->get("/uz/product/{$product->slug}")->getContent())->toContain('Yorliq oʻlchami 57 × 25.4 mm', '500 dona · gilza 40 mm')
            ->and($this->get("/ru/product/{$product->slug}")->getContent())->toContain('Размер этикетки 57 × 25.4 мм', '500 шт. · втулка 40 мм');
    });

    it('draws the diagram on search results', function () {
        $product = diagramProduct(40, 30, attributes: ['name' => ['uz' => 'Zxqv yorliq', 'ru' => 'Zxqv yorliq', 'en' => 'Zxqv label']]);

        expect($this->get('/en/search?q=Zxqv')->assertOk()->getContent())->toContain("label-diagram-{$product->id}-card");
    });

    it('shows the placeholder for invalid stored sizes', function (float|int|string|null $width, float|int|string|null $height) {
        $product = diagramProduct($width, $height);

        expect($this->get('/en/catalog/thermal-labels')->assertOk()->getContent())->not->toContain('data-label-diagram')
            ->and($this->get("/en/product/{$product->slug}")->assertOk()->getContent())->not->toContain('data-label-diagram')
            ->toContain('<use href="#p-');
    })->with([
        'zero' => [0, 150],
        'negative' => [-5, 150],
        'too big' => [301, 150],
        'null' => [null, 150],
        'null height' => [100, null],
    ]);

    it('never draws for a product with an image or a non-label product', function () {
        Storage::fake('public');
        Storage::fake('local');

        $withImage = diagramProduct(100, 150);
        $withImage->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaCollection(Product::IMAGES);

        $plain = Product::factory()->inCategory(Category::query()->where('slug', 'thermal-labels')->first())->create(['slug' => 'plain-one']);
        foreach (['label_width' => 100, 'label_height' => 150] as $code => $value) {
            ProductAttributeValue::factory()->for($plain)->number($value)->create(['attribute_id' => Attribute::query()->where('code', $code)->first()->id]);
        }

        expect($this->get('/en/catalog/thermal-labels')->assertOk()->getContent())->not->toContain('data-label-diagram')
            ->and($this->get("/en/product/{$withImage->slug}")->getContent())->not->toContain('data-label-diagram')
            ->and($this->get('/en/product/plain-one')->getContent())->not->toContain('data-label-diagram');
    });

    it('escapes markup in names and never lets it into the svg', function () {
        $product = diagramProduct(100, 150, attributes: ['name' => ['uz' => '<script>alert(1)</script>', 'ru' => '<script>alert(1)</script>', 'en' => '<script>alert(1)</script>']]);

        $html = $this->get("/en/product/{$product->slug}")->assertOk()->getContent();

        expect($html)->not->toContain('<script>alert(1)</script>');
        preg_match('#<svg[^>]*data-label-diagram.*?</svg>#s', $html, $svg);
        expect($svg[0] ?? '')->not->toContain('alert');
    });

    it('shows the roll caption only with exactly one roll type', function () {
        $none = diagramProduct(100, 150, [], ['slug' => 'no-roll']);
        $two = diagramProduct(100, 150, [RollType::factory()->size(501, '40.0')->create(), RollType::factory()->size(1001, '25.0')->create()], ['slug' => 'two-rolls']);
        $inactiveSecond = diagramProduct(100, 150, [RollType::factory()->size(300, '76.0')->create(), RollType::factory()->size(1002, '25.0')->inactive()->create()], ['slug' => 'one-active']);

        $none = $this->get('/en/product/no-roll')->getContent();
        $two = $this->get('/en/product/two-rolls')->getContent();
        $one = $this->get('/en/product/one-active')->getContent();

        expect($none)->toContain('data-label-diagram')->not->toContain(' pcs · core')
            ->and($two)->toContain('data-label-diagram')->not->toContain(' pcs · core')
            ->and($one)->toContain('300 pcs · core 76 mm');
    });
});

describe('queries and SEO', function () {
    it('adds no queries for a page without label products and a constant number for label products', function () {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            test()->get('/en/catalog/thermal-labels')->assertOk();

            return count(DB::getQueryLog());
        };

        $plain = Product::factory()->inCategory(Category::query()->where('slug', 'thermal-labels')->first() ?? Category::factory()->create(['slug' => 'thermal-labels']))->create();
        $count();
        $none = $count();
        $sizeQueries = 0;
        DB::listen(function ($query) use (&$sizeQueries) {
            $sizeQueries += str_contains($query->sql, 'product_attribute_values') && str_contains($query->sql, 'attributes') && str_contains($query->sql, 'label_') ? 1 : 0;
        });
        $count();

        expect($sizeQueries)->toBe(0);

        diagramProduct();
        $count();
        $one = $count();

        foreach (range(1, 6) as $_) {
            diagramProduct();
        }
        $count();

        expect($count())->toBe($one)->and($one)->toBeGreaterThan($none)->and($one - $none)->toBeLessThanOrEqual(3);
        expect($plain->exists)->toBeTrue();
    });

    it('keeps the placeholder when media exists but has no thumb conversion yet', function () {
        Storage::fake('public');
        Storage::fake('local');

        $product = diagramProduct(100, 150);
        $media = $product->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaCollection(Product::IMAGES);
        DB::table('media')->where('id', $media->id)->update(['generated_conversions' => '[]']);

        expect($this->get('/en/catalog/thermal-labels')->assertOk()->getContent())->not->toContain('data-label-diagram')->toContain('<use href="#p-')
            ->and($this->get("/en/product/{$product->slug}")->assertOk()->getContent())->not->toContain('data-label-diagram');
    });

    it('gives every rendered diagram unique title and clipPath ids, dimension values and no style', function () {
        $diagram = LabelDiagram::make(100.0, 150.0, null, 7);
        $render = fn (): string => Blade::render('<x-catalog.label-diagram :diagram="$diagram" />', ['diagram' => $diagram]);
        $one = $render();
        $two = $render();

        preg_match('/<title id="([^"]+)"/', $one, $a);
        preg_match('/<title id="([^"]+)"/', $two, $b);
        preg_match('/<clipPath id="([^"]+)"/', $one, $c);
        preg_match('/<clipPath id="([^"]+)"/', $two, $e);

        expect($a[1])->toStartWith('label-diagram-7-card-')->and($a[1])->not->toBe($b[1])
            ->and($c[1])->not->toBe($e[1])->and($one)->toContain('url(#'.$c[1].')')
            ->and($one)->toContain('>100 mm</text>')->toContain('>150 mm</text>')
            ->not->toContain('style=')->not->toContain('<marker')->not->toContain('href');
    });

    it('leaves og:image, JSON-LD and the sitemap untouched', function () {
        $product = diagramProduct();

        $html = $this->get("/en/product/{$product->slug}")->assertOk()->getContent();
        $product = collect(jsonLdBlocks($html))->firstWhere('@type', 'Product');
        $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();

        expect($html)->not->toMatch('#<meta property="og:image" content="[^"]*(svg|label-diagram|data:)#')
            ->and(json_encode($product))->not->toContain('svg')->not->toContain('label-diagram')
            ->and($sitemap)->not->toContain('<image:')->not->toContain('label-diagram');
    });
});

it('has the label diagram lang keys in all locales', function (string $locale) {
    foreach (['title', 'mm', 'caption'] as $key) {
        expect(__("catalog.label_diagram.{$key}", [], $locale))->not->toBe("catalog.label_diagram.{$key}");
    }

    expect(__('catalog.label_diagram.title', [], 'uz'))->toContain('ʻ')->not->toContain("'");
})->with(['uz', 'ru', 'en']);

it('gives the no-photo gallery the full-width single column', function () {
    $product = diagramProduct();

    $html = $this->get("/en/product/{$product->slug}")->assertOk()->getContent();

    expect($html)->toContain('md:grid-cols-1')->not->toContain('md:grid-cols-[84px_1fr]');
});
