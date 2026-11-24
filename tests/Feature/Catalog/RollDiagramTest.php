<?php

use App\Data\Site\ProductCard;
use App\Enums\ProductType;
use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Support\Catalog\LabelDiagram;
use App\Support\Catalog\ProductCards;
use App\Support\Catalog\ProductDiagram;
use App\Support\Catalog\RollDiagram;
use App\Support\Site\HomeCache;
use Database\Seeders\CatalogSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Plan 38: generated roll diagrams (ribbons, receipt paper). Groups follow the
 * security conditions RD-7, RD-8, RD-12, RD-13 and RD-14.
 */
beforeEach(function () {
    openSearchIndexing();
    $this->seed(CatalogSeeder::class);
});

function rollOption(string $code, string $uz): AttributeOption
{
    return Attribute::query()->where('code', $code)->firstOrFail()->options()->get()
        ->firstOrFail(fn (AttributeOption $option): bool => $option->getTranslation('value', 'uz', false) === $uz);
}

function rollSpec(Product $product, string $code, mixed $value): void
{
    $attribute = Attribute::query()->where('code', $code)->firstOrFail();

    if ($attribute->type->hasOptions()) {
        ProductAttributeValue::factory()->for($product)->option(rollOption($code, (string) $value))->create();

        return;
    }

    $row = ProductAttributeValue::factory()->for($product)->create(['attribute_id' => $attribute->id]);
    DB::table('product_attribute_values')->where('id', $row->id)->update(['value_number' => $value, 'value_text' => null]);
}

/**
 * @param  array<string, mixed>  $specs  code => value (null = no value)
 * @param  array<string, mixed>  $attributes
 */
function ribbonProduct(array $specs = [], array $attributes = []): Product
{
    $product = Product::factory()->consumable()->inCategory(Category::query()->where('slug', 'ribbons')->firstOrFail())->create([
        'slug' => 'ribbon-'.fake()->unique()->numerify('#####'),
        ...$attributes,
    ]);

    $defaults = ['ribbon_type' => 'Resin (smola)', 'ribbon_width' => 110, 'ribbon_length' => 300, 'ink_side' => 'OUT', 'core_diameter' => '25', 'ribbon_colour' => 'Qora'];

    foreach ([...$defaults, ...$specs] as $code => $value) {
        if ($value !== null) {
            rollSpec($product, $code, $value);
        }
    }

    return $product;
}

/**
 * @param  array<string, mixed>  $specs
 * @param  array<string, mixed>  $attributes
 */
function receiptProduct(array $specs = [], array $attributes = [], string $category = 'receipt-paper'): Product
{
    $product = Product::factory()->consumable()->inCategory(Category::query()->where('slug', $category)->firstOrFail())->create([
        'slug' => 'receipt-'.fake()->unique()->numerify('#####'),
        ...$attributes,
    ]);

    foreach ([...['paper_width' => '80', 'paper_length' => 80, 'roll_core' => 'Gilzali'], ...$specs] as $code => $value) {
        if ($value !== null) {
            rollSpec($product, $code, $value);
        }
    }

    return $product;
}

function rollSvg(string $html): string
{
    preg_match('#<svg[^>]*data-roll-diagram.*?</svg>#s', $html, $svg);

    return $svg[0] ?? '';
}

describe('drawing', function () {
    it('draws a ribbon on the card and the product page with size, core, type tag, caption and colour', function () {
        $product = ribbonProduct(['ribbon_colour' => 'Yashil']);

        $listing = $this->get('/en/catalog/ribbons')->assertOk()->getContent();
        $page = $this->get("/en/product/{$product->slug}")->assertOk()->getContent();

        foreach ([$listing, $page] as $html) {
            $svg = rollSvg($html);

            expect($svg)->toContain('data-kind="ribbon"')
                ->toContain('role="img"')
                ->toContain('Ribbon 110 mm × 300 m')
                ->toContain('>Ø25 mm</text>')
                ->toContain('>Resin</text>')
                ->toContain('Resin · ink side OUT')
                ->toContain('>110 mm</text>')
                ->toContain('>300 m</text>')
                ->toContain('fill="#00843D"');
        }

        expect($listing)->toContain("roll-diagram-{$product->id}-card")
            ->and($page)->toContain("roll-diagram-{$product->id}-gallery");
        expectNoBannedWording($page);
    });

    it('draws a receipt roll with the thermal caption, coreless as a spiral', function () {
        $withCore = receiptProduct();
        $coreless = receiptProduct(['paper_width' => '56', 'paper_length' => 12, 'roll_core' => 'Gilzasiz']);

        $a = rollSvg($this->get("/en/product/{$withCore->slug}")->assertOk()->getContent());
        $b = rollSvg($this->get("/en/product/{$coreless->slug}")->assertOk()->getContent());

        expect($a)->toContain('data-kind="receipt"')->toContain('Receipt roll 80 mm × 80 m')->toContain('>Thermal paper</text>')->not->toContain('Ø')->not->toContain('fill="#')
            ->and($b)->toContain('Receipt roll 56 mm × 12 m')->toContain('Thermal paper · coreless');

        $diagram = RollDiagram::make('receipt', 56, 12, coreless: true);
        expect($diagram->spiralPath)->not->toBe('')->and($diagram->coreRx)->toBeNull()
            ->and(RollDiagram::make('receipt', 80, 80)->spiralPath)->toBe('');
    });

    it('is localized in uz, ru and en', function () {
        $product = ribbonProduct();
        $receipt = receiptProduct();

        expect(rollSvg($this->get("/uz/product/{$product->slug}")->getContent()))->toContain('Ribbon 110 mm × 300 m', 'boʻyoq tomoni OUT')
            ->and(rollSvg($this->get("/ru/product/{$product->slug}")->getContent()))->toContain('Риббон 110 мм × 300 м', 'Ø25 мм', 'красящий слой OUT')
            ->and(rollSvg($this->get("/uz/product/{$receipt->slug}")->getContent()))->toContain('Chek lentasi 80 mm × 80 m', 'Termoqogʻoz');
    });

    it('draws the ink band at the rim for OUT and next to the core for IN, and the body as film back for IN', function () {
        $out = RollDiagram::make('ribbon', 110, 300, 25, 'out', 'resin', '#C8102E');
        $in = RollDiagram::make('ribbon', 110, 300, 25, 'in', 'resin', '#C8102E');
        $none = RollDiagram::make('ribbon', 110, 300, 25, null, 'resin', '#C8102E');

        expect($out->bodyHex)->toBe('#C8102E')->and($out->bandHex)->toBe('#C8102E')
            ->and($in->bodyHex)->toBeNull()->and($in->bodyClass)->toBe('fill-ink-soft')->and($in->bandHex)->toBe('#C8102E')
            ->and($in->bandPath)->not->toBe($out->bandPath)
            ->and($none->bandPath)->toBe('')->and($none->bandHex)->toBeNull();
    });

    it('picks outline, hatch and text classes from the closed pair by luminance', function () {
        expect(RollDiagram::make('ribbon', 60, 300, hex: '#FFFFFF')->textClass)->toBe(RollDiagram::TONES['light']['text'])
            ->and(RollDiagram::make('ribbon', 60, 300, hex: '#FFD100')->outlineClass)->toBe(RollDiagram::TONES['light']['outline'])
            ->and(RollDiagram::make('ribbon', 60, 300, hex: '#111111')->hatchClass)->toBe(RollDiagram::TONES['dark']['hatch'])
            ->and(RollDiagram::make('ribbon', 60, 300, hex: '#0033A0')->textClass)->toBe(RollDiagram::TONES['dark']['text']);
    });

    it('keeps the geometry inside the viewBox for the extremes of both kinds', function (string $kind, float $width, float $length, ?float $core) {
        $d = RollDiagram::make($kind, $width, $length, $core, 'out', 'wax_resin');

        preg_match_all('/-?\d+(?:\.\d+)?/', $d->bodyPath.$d->stripPath.$d->ringsPath.$d->leaderPath.$d->extensionPath.$d->dimensionLinePath, $numbers);

        foreach ($numbers[0] as $number) {
            expect((float) $number)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(RollDiagram::BASE_SIZE);
        }

        expect($d->tagX)->toBeGreaterThanOrEqual(0.0)
            ->and($d->widthTextX)->toBeLessThan(RollDiagram::BASE_SIZE)
            ->and($d->faceX - $d->faceRx)->toBeGreaterThanOrEqual(0.0)
            ->and($d->withVariant('gallery')->viewSize)->toBe(300)
            ->and($d->withVariant('nope')->variant)->toBe('card');
    })->with([
        'narrow short ribbon' => ['ribbon', 10.0, 10.0, 12.7],
        'wide long ribbon' => ['ribbon', 250.0, 2000.0, 76.0],
        'ribbon without core' => ['ribbon', 110.0, 300.0, null],
        'narrow receipt' => ['receipt', 20.0, 5.0, null],
        'wide receipt' => ['receipt', 120.0, 500.0, null],
    ]);

    it('never draws for a product with an image, a label product or a receipt printer', function () {
        Storage::fake('public');
        Storage::fake('local');

        $withImage = ribbonProduct();
        $withImage->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaCollection(Product::IMAGES);

        $printer = Product::factory()->inCategory(Category::query()->where('slug', 'receipt-printers')->firstOrFail())->create(['slug' => 'receipt-printer-one']);
        rollSpec($printer, 'paper_width', '80');
        rollSpec($printer, 'paper_length', 80);

        $label = Product::factory()->perLabel()->create(['slug' => 'label-one']);
        rollSpec($label, 'ribbon_width', 110);
        rollSpec($label, 'ribbon_length', 300);

        foreach ([$withImage->slug, 'receipt-printer-one', 'label-one'] as $slug) {
            expect($this->get("/en/product/{$slug}")->assertOk()->getContent())->not->toContain('data-roll-diagram');
        }
    });

    it('draws no ribbon diagram for a hardware product carrying ribbon width and length', function () {
        $hardware = Product::factory()->create(['type' => ProductType::Hardware, 'slug' => 'hardware-with-ribbon-specs']);
        rollSpec($hardware, 'ribbon_width', 110);
        rollSpec($hardware, 'ribbon_length', 300);

        expect(RollDiagram::for($hardware->load('media', 'attributeValues.attribute', 'attributeValues.option')))->toBeNull()
            ->and($this->get('/en/product/hardware-with-ribbon-specs')->assertOk()->getContent())->not->toContain('data-roll-diagram');
    });

    /*
     * ProductCards::fromProduct() draws the diagram only when the product has no media at all
     * (and so no thumb URL). Media without a generated conversion keeps the placeholder symbol
     * (no diagram, no image); media with a thumb conversion shows the image.
     */
    it('draws the card diagram only without media: media without a conversion keeps the placeholder, a thumb shows the image', function () {
        Storage::fake('public');
        Storage::fake('local');
        $card = fn (Product $product): ProductCard => ProductCards::fromProducts([Product::query()->with(ProductCards::EAGER_LOADS)->findOrFail($product->id)])[0];

        $plain = ribbonProduct();
        $pending = ribbonProduct();
        $pendingMedia = $pending->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaCollection(Product::IMAGES);
        DB::table('media')->where('id', $pendingMedia->id)->update(['generated_conversions' => '[]']);
        $converted = ribbonProduct();
        $convertedMedia = $converted->addMedia(UploadedFile::fake()->image('b.jpg'))->toMediaCollection(Product::IMAGES);
        DB::table('media')->where('id', $convertedMedia->id)->update(['generated_conversions' => json_encode(['thumb' => true])]);

        expect($card($plain)->diagram)->toBeInstanceOf(RollDiagram::class)
            ->and($card($plain)->image)->toBeNull()
            ->and($card($pending)->diagram)->toBeNull()
            ->and($card($pending)->image)->toBeNull()
            ->and($card($converted)->diagram)->toBeNull()
            ->and($card($converted)->image)->not->toBeNull();

        $listing = $this->get('/en/catalog/ribbons')->assertOk()->getContent();

        expect($listing)->toContain("roll-diagram-{$plain->id}-card")
            ->not->toContain("roll-diagram-{$pending->id}-card")
            ->not->toContain("roll-diagram-{$converted->id}-card");
    });

    it('shows the placeholder for out-of-range stored sizes', function (array $specs) {
        $product = ribbonProduct($specs);

        expect($this->get("/en/product/{$product->slug}")->assertOk()->getContent())->not->toContain('data-roll-diagram')->toContain('<use href="#p-');
    })->with([
        'width too small' => [['ribbon_width' => 5]],
        'width too big' => [['ribbon_width' => 251]],
        'length too big' => [['ribbon_length' => 2001]],
        'no length' => [['ribbon_length' => null]],
    ]);

    it('leaves og:image, JSON-LD and the sitemap untouched', function () {
        $product = ribbonProduct();

        $html = $this->get("/en/product/{$product->slug}")->assertOk()->getContent();
        $jsonLd = collect(jsonLdBlocks($html))->firstWhere('@type', 'Product');

        expect($html)->not->toMatch('#<meta property="og:image" content="[^"]*(svg|roll-diagram|data:)#')
            ->and(json_encode($jsonLd))->not->toContain('svg')->not->toContain('roll-diagram')->not->toContain('#111111')
            ->and($this->get('/sitemap.xml')->assertOk()->getContent())->not->toContain('roll-diagram');
    });
});

describe('RD-7 cache round-trip', function () {
    it('uses HomeCache schema 3 and round-trips a roll diagram card', function () {
        $card = ProductCards::fromProduct(ribbonProduct()->load([...ProductCards::EAGER_LOADS, 'rollSpecValues.attribute', 'rollSpecValues.option']));

        expect(HomeCache::SCHEMA)->toBe(3)
            ->and($card->diagram)->toBeInstanceOf(RollDiagram::class)
            ->and($card->toArray()['diagram']['kind'])->toBe('roll')
            ->and(ProductCard::fromArray($card->toArray())->toArray())->toBe($card->toArray());
    });

    it('re-validates a tampered cached diagram: bad hex becomes the default, a bad width drops the diagram', function () {
        $tampered = RollDiagram::fromArray([...RollDiagram::make('ribbon', 110, 300)->toArray(), 'hex' => '"><g onload=x>']);

        expect($tampered->hex)->toBe(RollDiagram::DEFAULT_HEX)
            ->and(RollDiagram::fromArray([...RollDiagram::make('ribbon', 110, 300)->toArray(), 'width' => 9999]))->toBeNull()
            ->and(ProductDiagram::fromArray(['kind' => 'roll', 'data' => [...RollDiagram::make('ribbon', 110, 300)->toArray(), 'width' => 9999]]))->toBeNull()
            ->and(RollDiagram::fromArray([...RollDiagram::make('ribbon', 110, 300)->toArray(), 'kind' => 'evil']))->toBeNull()
            ->and(RollDiagram::fromArray([...RollDiagram::make('ribbon', 110, 300)->toArray(), 'ink_side' => '"/>', 'ribbon_type' => '<b>', 'core' => 33]))
            ->inkSide->toBeNull()->ribbonType->toBeNull()->core->toBeNull();

        expect(fn () => ProductDiagram::fromArray(['kind' => 'evil', 'data' => []]))->toThrow(InvalidArgumentException::class)
            ->and(ProductDiagram::fromArray(ProductDiagram::toArray(LabelDiagram::make(100.0, 150.0))))->toBeInstanceOf(LabelDiagram::class);
    });

    it('rebuilds the home payload when a cached diagram has an unknown kind', function () {
        config(['cache.stores.array.serialize' => true]);
        Cache::forgetDriver('array');
        Cache::flush();
        ribbonProduct(attributes: ['is_featured' => true]);

        $this->get('/uz')->assertOk();
        $key = HomeCache::key('uz');
        $payload = Cache::get($key);
        $index = collect($payload['products'])->search(fn (array $card): bool => $card['diagram'] !== null);
        expect($index)->not->toBeFalse();

        $payload['products'][$index]['diagram'] = ['kind' => 'evil', 'data' => ['hex' => '"><g onload=x>']];
        Cache::put($key, $payload, 600);
        Livewire::flushState();

        $html = $this->get('/uz')->assertOk()->getContent();

        expect($html)->not->toContain('onload')->toContain('data-roll-diagram')
            ->and(Cache::get($key)['products'][$index]['diagram']['kind'])->toBe('roll');
    });
});

describe('RD-8 svg safety', function () {
    it('renders no style, script, href or url() and no database text, and fill equals the normalized hex', function () {
        rollOption('ribbon_colour', 'Qora')->forceFill(['value' => ['uz' => '<b>x</b>', 'ru' => '<b>x</b>', 'en' => '<b>x</b>'], 'hex_color' => '#abcdef'])->save();
        Attribute::query()->where('code', 'ribbon_type')->firstOrFail()->options()->get()->each(fn (AttributeOption $option) => $option->forceFill(['value' => ['uz' => 'Resin <b>x</b>', 'en' => 'Resin <b>x</b>']])->save());
        $product = ribbonProduct(['ribbon_colour' => '<b>x</b>', 'ribbon_type' => 'Resin <b>x</b>']);

        $svg = rollSvg($this->get("/en/product/{$product->slug}")->assertOk()->getContent());

        expect($svg)->not->toBe('')
            ->not->toContain('style=')->not->toContain('<script')->not->toContain('href')->not->toContain('url(')
            ->not->toContain('<b>')->not->toContain('&lt;b&gt;')->not->toContain('x</')
            ->toContain('fill="#ABCDEF"')->not->toContain('#abcdef');

        preg_match_all('/fill="(#[^"]*)"/', $svg, $fills);
        foreach ($fills[1] as $fill) {
            expect($fill)->toMatch('/\A#[0-9A-F]{6}\z/');
        }
    });

    it('gives every rendered diagram a unique title id', function () {
        $diagram = RollDiagram::make('ribbon', 110, 300, productId: 7);
        $render = fn (): string => Blade::render('<x-catalog.product-diagram :diagram="$diagram" class="size-10" />', ['diagram' => $diagram]);

        preg_match('/<title id="([^"]+)"/', $one = $render(), $a);
        preg_match('/<title id="([^"]+)"/', $render(), $b);

        expect($a[1])->toStartWith('roll-diagram-7-card-')->and($a[1])->not->toBe($b[1])
            ->and($one)->toContain('class="size-10"');
    });
});

describe('RD-12 parsing', function () {
    it('accepts only plain in-range numbers', function (mixed $value, ?float $expected) {
        expect(RollDiagram::number($value, 10.0, 250.0))->toBe($expected);
    })->with([
        ['60', 60.0],
        ['60.0000', 60.0],
        [110, 110.0],
        [25.4, 25.4],
        ["60\n", null],
        [' 60', null],
        ['1e3', null],
        [1e3, null],
        [-5, null],
        ['-5', null],
        [NAN, null],
        [INF, null],
        ['', null],
        [null, null],
        [[60], null],
        [true, null],
        [9, null],
        [251, null],
    ]);

    it('maps core, ink side, ribbon type and roll core through allow-lists and anchored matches', function () {
        expect(RollDiagram::core('25'))->toBe(25.0)
            ->and(RollDiagram::core('25.4'))->toBe(25.4)
            ->and(RollDiagram::core('12.7'))->toBe(12.7)
            ->and(RollDiagram::core('76'))->toBe(76.0)
            ->and(RollDiagram::core('30'))->toBeNull()
            ->and(RollDiagram::core("25\n"))->toBeNull()
            ->and(RollDiagram::inkSide('OUT'))->toBe('out')
            ->and(RollDiagram::inkSide('IN'))->toBe('in')
            ->and(RollDiagram::inkSide('out'))->toBeNull()
            ->and(RollDiagram::inkSide("IN\n"))->toBeNull()
            ->and(RollDiagram::inkSide(' OUT'))->toBeNull()
            ->and(RollDiagram::ribbonType(['en' => 'Wax-resin', 'uz' => 'Wax-resin (mum-smola)']))->toBe('wax_resin')
            ->and(RollDiagram::ribbonType(['en' => 'Wax', 'uz' => 'Wax (mum)']))->toBe('wax')
            ->and(RollDiagram::ribbonType(['en' => '', 'uz' => 'Resin (smola)']))->toBe('resin')
            ->and(RollDiagram::ribbonType(['en' => 'Premium waxy', 'uz' => 'x']))->toBeNull()
            ->and(RollDiagram::ribbonType(['en' => 'Waxy']))->toBeNull()
            ->and(RollDiagram::rollCore(['en' => 'Coreless']))->toBe('coreless')
            ->and(RollDiagram::rollCore(['uz' => 'Gilzali']))->toBe('with_core')
            ->and(RollDiagram::rollCore(['en' => 'Coreless!', 'uz' => 'x']))->toBeNull();
    });

    it('needs width and length: an unknown kind or a missing value means no diagram', function () {
        expect(RollDiagram::make('ribbon', null, 300))->toBeNull()
            ->and(RollDiagram::make('receipt', 130, 80))->toBeNull()
            ->and(RollDiagram::make('receipt', 80, 4))->toBeNull()
            ->and(RollDiagram::make('label', 80, 80))->toBeNull()
            ->and(RollDiagram::make('receipt', '80', '80'))->toBeInstanceOf(RollDiagram::class);
    });

    it('returns null without queries when the relations are not loaded, and draws no receipt printer', function () {
        $product = Product::query()->findOrFail(ribbonProduct()->id);

        expect(countQueries(fn () => expect(RollDiagram::for($product))->toBeNull()))->toBe(0);

        $product->load('media', 'attributeValues');
        expect(countQueries(fn () => expect(RollDiagram::for($product))->toBeNull()))->toBe(0);

        $product->load('attributeValues.attribute');
        $diagram = null;
        expect(countQueries(function () use ($product, &$diagram) {
            $diagram = RollDiagram::for($product);
        }))->toBe(0)
            ->and($diagram)->toBeInstanceOf(RollDiagram::class)
            ->and($diagram->hex)->toBe(RollDiagram::DEFAULT_HEX)
            ->and($diagram->core)->toBeNull();

        $printer = Product::factory()->create(['type' => ProductType::Hardware]);
        rollSpec($printer, 'paper_width', '80');
        rollSpec($printer, 'paper_length', 80);

        expect(RollDiagram::for($printer->load('media', 'attributeValues.attribute', 'attributeValues.option')))->toBeNull();
    });

    it('adds a constant number of queries to a listing, whatever the number of rolls', function () {
        $count = fn (): int => countQueries(fn () => test()->get('/en/catalog/ribbons')->assertOk());

        ribbonProduct();
        $count();
        $one = $count();

        foreach (range(1, 5) as $_) {
            ribbonProduct();
        }

        expect($count())->toBe($one);
    });
});

describe('RD-13 filter swatch', function () {
    it('shows a dot before the colour label, none for an invalid stored hex, and the paper length range filter', function () {
        ribbonProduct(['ribbon_colour' => 'Yashil']);
        ribbonProduct(['ribbon_colour' => 'Qora']);
        receiptProduct(['paper_length' => 12]);
        receiptProduct(['paper_length' => 80]);
        DB::table('attribute_options')->where('id', rollOption('ribbon_colour', 'Qora')->id)->update(['hex_color' => '#000;x']);

        $html = $this->get('/en/catalog/ribbons')->assertOk()->getContent();
        preg_match('#data-filter="ribbon_colour".*?</fieldset>#s', $html, $fieldset);

        expect($fieldset[0] ?? '')->toContain('fill="#00843D"')->toContain('Green')->toContain('Black')
            ->not->toContain('#000;x')->not->toContain('style=')
            ->and(substr_count($fieldset[0], 'data-swatch'))->toBe(1)
            ->and($this->get('/en/catalog/receipt-paper')->assertOk()->getContent())->toContain('data-filter="paper_length"');
    });
});

describe('paper length range filter', function () {
    it('lists only the rolls inside the from / to range', function () {
        $short = receiptProduct(['paper_length' => 12], ['slug' => 'receipt-roll-short']);
        $long = receiptProduct(['paper_length' => 80], ['slug' => 'receipt-roll-long']);

        $all = $this->get('/en/catalog/receipt-paper')->assertOk()->getContent();
        $filtered = $this->get('/en/catalog/receipt-paper?f[paper_length][min]=20&f[paper_length][max]=100')->assertOk()->getContent();

        expect($all)->toContain('/product/receipt-roll-short')->toContain('/product/receipt-roll-long')
            ->and($filtered)->toContain('/product/receipt-roll-long')->not->toContain('/product/receipt-roll-short')
            ->and($short->exists && $long->exists)->toBeTrue();
    });
});

describe('RD-14 no hex in MCP or JSON-LD', function () {
    it('keeps the option shape of list_categories without hex_color', function () {
        mcpTelegramConfigured();
        mcpHolder($plain = fakeMcpTokenValue());

        $items = mcpCallTool($plain, 'list_categories', ['with_attributes' => true])->assertOk()->json('result.structuredContent.items');
        $ribbons = collect($items)->firstWhere('slug', 'ribbons');
        $colour = collect($ribbons['attributes'])->firstWhere('code', 'ribbon_colour');

        expect($colour['options'][0])->toHaveKeys(['id', 'value'])->not->toHaveKey('hex_color')->not->toHaveKey('hex')
            ->and(json_encode($items))->not->toContain('#111111');
    });
});

it('has the roll diagram lang keys in all locales', function (string $locale) {
    foreach (['title_ribbon', 'title_receipt', 'size', 'mm', 'm', 'core', 'type.wax', 'type.wax_resin', 'type.resin', 'ink.in', 'ink.out', 'thermal', 'coreless'] as $key) {
        expect(__("catalog.roll_diagram.{$key}", [], $locale))->not->toBe("catalog.roll_diagram.{$key}");
    }

    expect(__('catalog.roll_diagram.ink.out', [], 'uz'))->toContain('ʻ')->not->toContain("'")
        ->and(__('catalog.roll_diagram.thermal', [], 'uz'))->toContain('ʻ');
})->with(['uz', 'ru', 'en']);
