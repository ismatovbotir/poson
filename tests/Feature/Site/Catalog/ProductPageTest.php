<?php

use App\Actions\Leads\SubmitSiteLead;
use App\Data\Leads\SiteLeadData;
use App\Enums\LeadType;
use App\Enums\RelationType;
use App\Enums\StockStatus;
use App\Livewire\Site\Catalog\Show;
use App\Livewire\Site\LeadForm;
use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeSkip;
use App\Models\ProductAttributeValue;
use App\Models\ProductDocument;
use App\Models\ProductRelation;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * A published scanner with brand, description, a parent category and specs.
 *
 * @param  array<string, mixed>  $attributes
 */
function pageProduct(array $attributes = []): Product
{
    $root = Category::factory()->create(['slug' => 'scanners', 'name' => ['uz' => 'Skanerlar', 'ru' => 'Сканеры', 'en' => 'Scanners']]);
    $child = Category::factory()->child($root)->create(['slug' => 'handheld', 'name' => ['uz' => 'Qoʻl skanerlari', 'ru' => 'Ручные', 'en' => 'Handheld']]);

    return Product::factory()->inCategory($child)->for(Brand::factory()->create(['name' => 'Zebra']))->create([
        'sku' => 'ZEB-DS2208',
        'model' => 'DS2208-SR',
        'slug' => 'zebra-ds2208',
        'price' => 145_000_000,
        'warranty_months' => 12,
        'name' => ['uz' => 'Zebra DS2208 skaneri', 'ru' => 'Сканер Zebra DS2208', 'en' => 'Zebra DS2208 scanner'],
        'short_description' => ['uz' => 'Kassa uchun 2D skaner.', 'ru' => 'Проводной 2D-сканер для кассы.', 'en' => 'Wired 2D scanner for the checkout.'],
        'description' => ['uz' => '<p>Tez <strong>skaner</strong></p>', 'ru' => '<p>Быстрый <strong>сканер</strong></p>', 'en' => '<p>Fast <strong>scanner</strong></p>'],
        ...$attributes,
    ]);
}

it('renders the product page in every locale', function (string $locale) {
    $product = pageProduct();

    $html = $this->get("/{$locale}/product/zebra-ds2208")->assertOk()->getContent();

    expect($html)
        ->toContain('<title>'.e($product->getTranslation('name', $locale)).' — Poson</title>')
        ->toContain('<meta name="description" content="'.e($product->getTranslation('short_description', $locale)).'">')
        ->toContain('<meta property="og:type" content="product">')
        ->toContain('<link rel="canonical" href="'.url("/{$locale}/product/zebra-ds2208").'">')
        ->toContain('<link rel="alternate" hreflang="x-default" href="'.url('/uz/product/zebra-ds2208').'">')
        ->toContain(e($product->getTranslation('name', $locale)).'</h1>')
        ->toContain('data-sku>ZEB-DS2208<')
        ->toContain('Zebra')
        ->toContain('data-stock="in_stock"')
        ->toContain('1'.Money::NBSP.'450'.Money::NBSP.'000')
        ->toContain($product->getTranslation('description', $locale))
        ->toContain(e(trans_choice('site.product.months', 12, ['count' => 12], $locale)))
        ->toContain('href="'.url("/{$locale}/catalog/handheld").'"')
        ->toContain('data-sticky-bar')
        ->toContain('id="quote"')
        ->toContain('data-lead-product');

    expect($html)->not->toContain('data-cta="cart"')
        ->not->toMatch('/★|rating|data-tier/u');
})->with(['uz', 'ru', 'en']);

it('returns 404 for products that are not on the site', function (string $state) {
    $product = pageProduct();

    match ($state) {
        'inactive' => $product->update(['is_active' => false]),
        'trashed' => $product->delete(),
        'inactive category' => $product->category->update(['is_active' => false]),
        'inactive parent category' => $product->category->parent->update(['is_active' => false]),
    };

    $this->get('/ru/product/zebra-ds2208')->assertNotFound();
})->with(['inactive', 'trashed', 'inactive category', 'inactive parent category']);

it('returns 404 for an unknown product', function () {
    $this->get('/ru/product/nope')->assertNotFound();
});

it('falls back to meta fields and trims long descriptions', function () {
    $product = pageProduct([
        'meta_title' => ['ru' => 'Сканер Zebra DS2208 для кассы'],
        'short_description' => ['ru' => str_repeat('Надёжный сканер для кассы и склада. ', 10)],
    ]);

    $html = $this->get('/ru/product/zebra-ds2208')->assertOk()->getContent();
    preg_match('/<meta name="description" content="([^"]*)">/', $html, $description);

    expect($html)->toContain('<title>Сканер Zebra DS2208 для кассы</title>')
        ->and(mb_strlen(html_entity_decode($description[1])))->toBeLessThanOrEqual(156)
        ->and($description[1])->toEndWith('…')
        // No meta title in uz: name + " — Poson", never the ru meta title.
        ->and($this->get('/uz/product/zebra-ds2208')->getContent())->toContain('<title>Zebra DS2208 skaneri — Poson</title>');
});

it('shows the specs table in resolver order without skipped rows', function () {
    $product = pageProduct();
    $root = $product->category->parent;
    $engine = Attribute::factory()->select(['2D imager', 'Laser'])->create(['code' => 'engine', 'name' => ['uz' => 'Modul', 'ru' => 'Модуль', 'en' => 'Engine']]);
    $codes = Attribute::factory()->multiselect(['QR Code', 'DataMatrix'])->create(['code' => 'codes', 'name' => ['uz' => 'Kodlar', 'ru' => 'Коды', 'en' => 'Codes']]);
    $warranty = Attribute::factory()->number('months')->create(['code' => 'warranty', 'name' => ['uz' => 'Kafolat', 'ru' => 'Гарантия', 'en' => 'Warranty']]);
    $cable = Attribute::factory()->boolean()->create(['code' => 'cable', 'name' => ['uz' => 'Kabel', 'ru' => 'Кабель', 'en' => 'Cable']]);
    $skipped = Attribute::factory()->create(['code' => 'battery', 'name' => ['uz' => 'Batareya', 'ru' => 'Аккумулятор', 'en' => 'Battery']]);
    $extra = Attribute::factory()->create(['code' => 'colour', 'name' => ['uz' => 'Rang', 'ru' => 'Цвет', 'en' => 'Colour']]);

    $root->attributes()->attach($engine->id, ['sort' => 1, 'is_required' => true, 'is_filterable' => true]);
    $product->category->attributes()->attach([
        $codes->id => ['sort' => 1, 'is_required' => false, 'is_filterable' => true],
        $warranty->id => ['sort' => 2, 'is_required' => false, 'is_filterable' => false],
        $cable->id => ['sort' => 3, 'is_required' => false, 'is_filterable' => false],
        $skipped->id => ['sort' => 4, 'is_required' => false, 'is_filterable' => false],
    ]);

    $option = fn (Attribute $attribute, string $value): AttributeOption => $attribute->options()->get()->first(fn ($option) => $option->getTranslation('value', 'uz') === $value);
    ProductAttributeValue::factory()->for($product)->option($option($codes, 'QR Code'))->create(['sort' => 2]);
    ProductAttributeValue::factory()->for($product)->option($option($codes, 'DataMatrix'))->create(['sort' => 2]);
    ProductAttributeValue::factory()->for($product)->option($option($engine, '2D imager'))->key()->create(['sort' => 1]);
    ProductAttributeValue::factory()->for($product)->number(12.5)->create(['attribute_id' => $warranty->id, 'sort' => 3]);
    ProductAttributeValue::factory()->for($product)->boolean(false)->create(['attribute_id' => $cable->id, 'sort' => 4]);
    ProductAttributeValue::factory()->for($product)->create(['attribute_id' => $skipped->id, 'value_text' => ['ru' => '2500 мА·ч']]);
    ProductAttributeValue::factory()->for($product)->create(['attribute_id' => $extra->id, 'value_text' => ['uz' => 'Qora', 'ru' => 'Чёрный']]);
    ProductAttributeSkip::factory()->create(['product_id' => $product->id, 'attribute_id' => $skipped->id]);

    $html = $this->get('/ru/product/zebra-ds2208')->assertOk()->getContent();
    preg_match_all('#<tr[^>]*data-spec-row>\s*<th[^>]*>([^<]+)</th>\s*<td[^>]*>([^<]+)</td>#', $html, $rows, PREG_SET_ORDER);

    expect(array_map(fn (array $row): array => [html_entity_decode($row[1]), html_entity_decode($row[2])], $rows))->toBe([
        ['Модуль', '2D imager'],
        ['Коды', 'QR Code; DataMatrix'],
        ['Гарантия', '12,5 месяца'],
        ['Кабель', 'Нет'],
        ['Цвет', 'Чёрный'],
    ])
        ->and($html)->toContain('<table class="w-full border-collapse text-sm" data-specs>')
        ->and($html)->not->toContain('Аккумулятор')
        ->and($html)->toMatch('#data-key-specs>.*?Модуль</dt>\s*<dd[^>]*>2D imager</dd>#s');
});

it('links documents: files through the public download route, links directly', function () {
    $product = pageProduct();
    $file = ProductDocument::factory()->for($product)->file()->create(['title' => ['ru' => 'Руководство'], 'locale' => 'ru']);
    ProductDocument::factory()->for($product)->create(['url' => 'https://example.com/ds2208.pdf', 'title' => ['ru' => 'Даташит']]);

    $html = $this->get('/ru/product/zebra-ds2208')->assertOk()->getContent();

    expect($html)->toContain('data-documents')
        ->toContain('href="'.url("/ru/product/zebra-ds2208/documents/{$file->id}").'" rel="nofollow" download')
        ->toContain('href="https://example.com/ds2208.pdf" target="_blank" rel="noopener nofollow"')
        ->toContain('Руководство')
        ->not->toContain($file->path)
        ->not->toContain('/admin/');
});

it('hides the documents tab without documents', function () {
    pageProduct();

    expect($this->get('/en/product/zebra-ds2208')->assertOk()->getContent())->not->toContain('id="tab-documents"');
});

it('streams a document of a published product as an attachment', function () {
    Storage::fake('local');
    $product = pageProduct();
    $path = UploadedFile::fake()->create('manual.pdf', 12, 'application/pdf')->store("product-documents/{$product->id}", 'local');
    $document = ProductDocument::factory()->for($product)->create(['path' => $path, 'url' => null, 'original_name' => 'DS2208 manual.pdf']);

    $response = $this->get("/en/product/zebra-ds2208/documents/{$document->id}")->assertOk();

    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment;')->toContain('DS2208 manual.pdf')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('refuses document downloads that are not public', function (string $case) {
    Storage::fake('local');
    $product = pageProduct();
    $path = UploadedFile::fake()->create('manual.pdf', 12, 'application/pdf')->store("product-documents/{$product->id}", 'local');
    $document = ProductDocument::factory()->for($product)->create(['path' => $path, 'url' => null]);
    $slug = 'zebra-ds2208';

    match ($case) {
        'inactive product' => $product->update(['is_active' => false]),
        'trashed product' => $product->delete(),
        'inactive category' => $product->category->update(['is_active' => false]),
        'another product' => $slug = Product::factory()->create()->slug,
        'link document' => $document->update(['path' => null, 'url' => 'https://example.com/a.pdf']),
        'missing file' => Storage::disk('local')->delete($path),
    };

    $this->get("/en/product/{$slug}/documents/{$document->id}")->assertNotFound();
})->with(['inactive product', 'trashed product', 'inactive category', 'another product', 'link document', 'missing file']);

it('lists published related products by type', function () {
    $product = pageProduct();
    $stand = Product::factory()->create(['name' => ['uz' => 'Stand', 'ru' => 'Подставка', 'en' => 'Stand']]);
    $labels = Product::factory()->consumable()->create(['name' => ['uz' => 'Labels', 'ru' => 'Этикетки', 'en' => 'Labels']]);
    $draft = Product::factory()->inactive()->create(['name' => ['uz' => 'Draft', 'ru' => 'Черновик', 'en' => 'Draft']]);
    $trashed = Product::factory()->create(['name' => ['uz' => 'Old', 'ru' => 'Старый', 'en' => 'Old']]);
    $trashed->delete();

    ProductRelation::factory()->create(['product_id' => $product->id, 'related_id' => $stand->id, 'type' => RelationType::Accessory]);
    ProductRelation::factory()->create(['product_id' => $product->id, 'related_id' => $labels->id, 'type' => RelationType::Consumable]);
    ProductRelation::factory()->create(['product_id' => $product->id, 'related_id' => $draft->id, 'type' => RelationType::Alternative]);
    ProductRelation::factory()->create(['product_id' => $product->id, 'related_id' => $trashed->id, 'type' => RelationType::Accessory]);

    $html = $this->get('/ru/product/zebra-ds2208')->assertOk()->getContent();

    expect($html)->toContain('data-related="accessory"')
        ->toContain('data-related="consumable"')
        ->not->toContain('data-related="alternative"')
        ->toContain('Подставка')
        ->toContain('Этикетки')
        ->not->toContain('Черновик')
        ->not->toContain('Старый');
});

it('adds Product, Offer and BreadcrumbList JSON-LD', function () {
    $product = pageProduct(['stock_status' => StockStatus::OnOrder, 'price' => 145_000_050]);

    $blocks = collect(jsonLdBlocks($this->get('/en/product/zebra-ds2208')->assertOk()->getContent()));
    $data = $blocks->firstWhere('@type', 'Product');
    $breadcrumbs = $blocks->firstWhere('@type', 'BreadcrumbList');

    expect($data)->toMatchArray([
        '@context' => 'https://schema.org',
        'name' => 'Zebra DS2208 scanner',
        'sku' => 'ZEB-DS2208',
        'mpn' => 'DS2208-SR',
        'brand' => ['@type' => 'Brand', 'name' => 'Zebra'],
        'description' => 'Wired 2D scanner for the checkout.',
        'category' => 'Scanners › Handheld',
        'url' => url('/en/product/zebra-ds2208'),
    ])
        ->and($data['offers'])->toBe([
            '@type' => 'Offer',
            'priceCurrency' => 'UZS',
            'price' => '1450000.50',
            'availability' => 'https://schema.org/BackOrder',
            'itemCondition' => 'https://schema.org/NewCondition',
            'url' => url('/en/product/zebra-ds2208'),
        ])
        ->and(array_column($breadcrumbs['itemListElement'], 'name'))->toBe(['Home', 'Catalog', 'Scanners', 'Handheld', 'Zebra DS2208 scanner'])
        ->and(end($breadcrumbs['itemListElement'])['item'])->toBe(url('/en/product/zebra-ds2208'));
});

it('shows "price on request" and leaves the Offer out without a Retail price', function (string $locale) {
    pageProduct(['price' => null, 'price_dealer' => 90_000_000, 'price_wholesale' => 80_000_000, 'sale_mode' => 'quote']);

    $html = $this->get("/{$locale}/product/zebra-ds2208")->assertOk()->getContent();
    $data = collect(jsonLdBlocks($html))->firstWhere('@type', 'Product');

    expect($html)->toContain(__('site.card.price_on_request', [], $locale))
        ->toContain(__('site.product.price_on_request_note', [], $locale))
        ->not->toContain('900'.Money::NBSP.'000')
        ->not->toContain('800'.Money::NBSP.'000')
        ->and($data)->not->toHaveKey('offers');
})->with(['uz', 'ru', 'en']);

it('uses the image conversions for the gallery and Open Graph', function () {
    Storage::fake('public');
    Storage::fake('local');
    $product = pageProduct();
    $product->addMedia(UploadedFile::fake()->image('front.jpg', 800, 800))->withCustomProperties(['alt' => ['ru' => 'Сканер спереди']])->toMediaCollection(Product::IMAGES);
    $product->addMedia(UploadedFile::fake()->image('side.jpg', 800, 800))->toMediaCollection(Product::IMAGES);
    $large = $product->fresh()->imageUrl('large');

    $html = $this->get('/ru/product/zebra-ds2208')->assertOk()->getContent();

    expect($large)->not->toBeNull()
        ->and($html)->toContain('<meta property="og:image" content="'.$large.'">')
        ->toContain('src="'.$large.'"')
        ->toContain('alt="Сканер спереди"')
        ->toContain(__('site.product.image', ['number' => 2], 'ru'))
        ->and(collect(jsonLdBlocks($html))->firstWhere('@type', 'Product')['image'])->toHaveCount(2)
        ->and($html)->not->toContain('front.jpg');
});

it('renders the product page without lazy loading or per-item queries', function () {
    $product = pageProduct();
    $attribute = Attribute::factory()->select(['A', 'B'])->create();
    $product->category->attributes()->attach($attribute->id, ['sort' => 1, 'is_required' => false, 'is_filterable' => true]);
    ProductAttributeValue::factory()->for($product)->option($attribute->options()->first())->key()->create();

    $relate = function (int $count) use ($product): void {
        foreach (range(1, $count) as $index) {
            $related = Product::factory()->create();
            ProductAttributeValue::factory()->for($related)->option(AttributeOption::query()->first())->key()->create();
            ProductRelation::factory()->create(['product_id' => $product->id, 'related_id' => $related->id, 'type' => RelationType::Accessory, 'sort' => $index]);
            ProductDocument::factory()->for($product)->create();
        }
    };

    $relate(1);
    $this->get('/ru/product/zebra-ds2208')->assertOk();
    $few = countQueries(fn () => $this->get('/ru/product/zebra-ds2208')->assertOk());

    $relate(5);
    $this->get('/ru/product/zebra-ds2208')->assertOk();
    $many = countQueries(fn () => $this->get('/ru/product/zebra-ds2208')->assertOk());

    expect($many)->toBe($few);
});

it('sends a quote lead with the product from the product page form', function () {
    $product = pageProduct();

    $this->mock(SubmitSiteLead::class)
        ->shouldReceive('handle')
        ->once()
        ->withArgs(fn (SiteLeadData $data): bool => $data->type === LeadType::Quote
            && $data->productId === $product->id
            && $data->productSku === 'ZEB-DS2208'
            && $data->phone === '+998901234567')
        ->andReturn('L-TEST');

    $component = Livewire::test(LeadForm::class, ['productId' => $product->id])
        ->assertSet('productId', $product->id)
        ->assertSee('Zebra DS2208 skaneri (ZEB-DS2208)')
        ->set('name', 'Aziz')
        ->set('phone', '90 123 45 67');

    $this->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();

    $component->call('submit')->assertHasNoErrors()->assertSet('sent', true);
});

it('keeps the lead product locked and drops unpublished products', function () {
    $draft = Product::factory()->inactive()->create();

    Livewire::test(LeadForm::class, ['productId' => $draft->id])
        ->assertSet('productId', null)
        ->assertSet('productLabel', null)
        ->assertDontSee('data-lead-product', false);

    Livewire::test(LeadForm::class)->set('productId', pageProduct()->id);
})->throws(CannotUpdateLockedPropertyException::class);

it('sends a callback lead without a product', function () {
    $this->mock(SubmitSiteLead::class)
        ->shouldReceive('handle')
        ->once()
        ->withArgs(fn (SiteLeadData $data): bool => $data->type === LeadType::Callback && $data->productId === null && $data->productSku === null)
        ->andReturn('L-TEST');

    $component = Livewire::test(LeadForm::class)->set('name', 'Aziz')->set('phone', '90 123 45 67');
    $this->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();
    $component->call('submit')->assertSet('sent', true);
});

it('formats counted units with the right plural form', function (string $locale, float $number, string $expected) {
    $product = pageProduct();
    $years = Attribute::factory()->number('years')->create(['code' => 'life', 'name' => ['uz' => 'Muddat', 'ru' => 'Срок', 'en' => 'Life']]);
    $product->category->attributes()->attach($years->id, ['sort' => 1, 'is_required' => false, 'is_filterable' => false]);
    ProductAttributeValue::factory()->for($product)->number($number)->create(['attribute_id' => $years->id]);

    expect($this->get("/{$locale}/product/zebra-ds2208")->assertOk()->getContent())->toMatch('#<td[^>]*>'.preg_quote($expected, '#').'</td>#');
})->with([
    'ru 2' => ['ru', 2, '2 года'],
    'ru 5' => ['ru', 5, '5 лет'],
    'ru 21' => ['ru', 21, '21 год'],
    'ru 1.5' => ['ru', 1.5, '1,5 года'],
    'en 1' => ['en', 1, '1 year'],
    'en 1250' => ['en', 1250, '1,250 years'],
    'uz 3' => ['uz', 3, '3 yil'],
]);

it('hides an inactive brand on the product page and in JSON-LD', function () {
    $product = pageProduct();
    $product->brand->update(['is_active' => false]);

    $html = $this->get('/en/product/zebra-ds2208')->assertOk()->getContent();

    expect($html)->not->toContain('Zebra</dd>')
        ->and(collect(jsonLdBlocks($html))->firstWhere('@type', 'Product'))->not->toHaveKey('brand');
});

it('shows the SHA-256 of downloadable files', function () {
    $product = pageProduct();
    $hash = hash('sha256', 'driver');
    ProductDocument::factory()->for($product)->file('exe')->create(['sha256' => $hash]);

    expect($this->get('/en/product/zebra-ds2208')->assertOk()->getContent())
        ->toContain('data-sha256="'.$hash.'"')
        ->toContain('title="'.$hash.'"')
        ->toContain(substr($hash, 0, 12).'…');
});

it('re-sanitizes the description on output', function () {
    pageProduct();
    Product::query()->where('slug', 'zebra-ds2208')->update(['description' => json_encode(['en' => '<p>Ok</p><script>alert(1)</script><img src=x onerror=alert(2)>'])]);

    $html = $this->get('/en/product/zebra-ds2208')->assertOk()->getContent();

    expect($html)->toContain('<p>Ok</p>')->not->toContain('alert(1)')->not->toContain('onerror');
});

it('returns 404 on update requests once the product leaves the site', function () {
    $product = pageProduct();
    URL::defaults(['locale' => 'en']);
    app()->setLocale('en');

    $component = Livewire::test(Show::class, ['product' => $product]);
    $product->update(['is_active' => false]);

    $component->call('$refresh')->assertStatus(404);
});

it('refuses a quote for a product that left the site after the form opened', function () {
    $product = pageProduct();
    $this->mock(SubmitSiteLead::class)->shouldNotReceive('handle');

    $component = Livewire::test(LeadForm::class, ['productId' => $product->id])
        ->set('name', 'Aziz')
        ->set('phone', '90 123 45 67');

    $product->update(['is_active' => false]);
    $this->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();

    $component->call('submit')
        ->assertHasErrors('form')
        ->assertSee(__('site.lead_form.product_unavailable'))
        ->assertSet('sent', false);
});
