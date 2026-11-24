<?php

use App\Actions\Catalog\DuplicateProduct;
use App\Enums\ProductBadge;
use App\Enums\RelationType;
use App\Enums\SaleMode;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\FaqItem;
use App\Models\NewsPost;
use App\Models\PriceSyncLog;
use App\Models\Product;
use App\Models\ProductAttributeSkip;
use App\Models\ProductAttributeValue;
use App\Models\ProductDocument;
use App\Models\ProductRelation;
use App\Models\RollType;
use App\Models\Video;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * A fully loaded source: texts, specs (text, number, multiselect, key, skip), roll types,
 * relations, FAQ, media, documents, video, news link, 3 prices and a 1C link.
 *
 * @return array{source: Product, category: Category, active: Product, inactive: Product, trashed: Product}
 */
function copySource(): array
{
    Storage::fake('public');
    Storage::fake('local');

    $category = Category::factory()->create();
    $text = Attribute::factory()->create(['code' => 'copy_text']);
    $multi = Attribute::factory()->multiselect(['QR', 'PDF417'])->create(['code' => 'copy_multi']);
    $optional = Attribute::factory()->boolean()->create(['code' => 'copy_opt']);
    $category->attributes()->attach([$text->id => ['sort' => 1], $multi->id => ['sort' => 2], $optional->id => ['sort' => 3]]);

    $source = Product::factory()->perLabel()->inCategory($category)->withIkpu()->withRollTypes([
        RollType::factory()->size(500, '40.0')->create(),
        RollType::factory()->size(1000, '40.0')->create(),
    ])->priceSynced()->create([
        'sku' => 'SRC-1',
        'name' => ['uz' => 'Manba mahsulot', 'ru' => 'Исходный товар', 'en' => ''],
        'short_description' => ['uz' => 'Qisqa', 'ru' => 'Коротко'],
        'description' => ['uz' => '<p>Tavsif</p>', 'ru' => '<p>Описание</p>'],
        'meta_title' => ['uz' => 'Meta sarlavha'],
        'meta_description' => ['uz' => 'Meta tavsif'],
        'badge' => ProductBadge::Hit,
        'is_featured' => true,
        'warranty_months' => 24,
        'min_qty' => 3,
        'price' => 100_000_00,
        'price_dealer' => 90_000_00,
        'price_wholesale' => 80_000_00,
    ]);
    DB::table('products')->where('id', $source->id)->update(['price_sync_log_id' => PriceSyncLog::factory()->create()->id]);

    ProductAttributeValue::factory()->create(['product_id' => $source->id, 'attribute_id' => $text->id, 'value_text' => ['uz' => 'Matn', 'ru' => 'Текст'], 'is_key' => true, 'sort' => 1]);
    foreach ($multi->options as $option) {
        ProductAttributeValue::factory()->option($option)->create(['product_id' => $source->id, 'sort' => 2]);
    }
    ProductAttributeSkip::factory()->create(['product_id' => $source->id, 'attribute_id' => $optional->id]);

    $active = Product::factory()->create();
    $inactive = Product::factory()->inactive()->create();
    $trashed = Product::factory()->create();
    ProductRelation::factory()->create(['product_id' => $source->id, 'related_id' => $active->id, 'type' => RelationType::Accessory, 'sort' => 2]);
    ProductRelation::factory()->create(['product_id' => $source->id, 'related_id' => $inactive->id, 'type' => RelationType::Consumable, 'sort' => 5]);
    ProductRelation::factory()->create(['product_id' => $source->id, 'related_id' => $trashed->id, 'type' => RelationType::Accessory, 'sort' => 1]);
    ProductRelation::factory()->create(['product_id' => $active->id, 'related_id' => $source->id, 'type' => RelationType::Accessory]);
    $trashed->delete();

    FaqItem::factory()->owner($source)->create(['sort' => 2, 'question' => ['uz' => 'Ikkinchi?', 'ru' => 'Второй?', 'en' => 'Second?'], 'answer' => ['uz' => 'Javob 2', 'ru' => 'Ответ 2', 'en' => 'Answer 2']]);
    FaqItem::factory()->owner($source)->create(['sort' => 1, 'is_active' => false, 'question' => ['uz' => 'Birinchi?', 'ru' => 'Первый?', 'en' => 'First?'], 'answer' => ['uz' => 'Javob 1', 'ru' => 'Ответ 1', 'en' => 'Answer 1']]);

    $source->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaCollection(Product::IMAGES);
    ProductDocument::factory()->create(['product_id' => $source->id]);
    Video::factory()->owner($source)->create();
    $source->newsPosts()->attach(NewsPost::factory()->create());

    return compact('source', 'category', 'active', 'inactive', 'trashed');
}

function copyOf(Product $source, array $overrides = []): Product
{
    return app(DuplicateProduct::class)->handle($source, [
        'sku' => 'copy-1',
        'name' => ['uz' => 'Manba mahsulot (nusxa)', 'ru' => 'Исходный товар (копия)', 'en' => ''],
        ...$overrides,
    ]);
}

it('copies texts, category, brand, type, specs with is_key and skips, roll types and the FAQ', function () {
    ['source' => $source] = copySource();
    $before = $source->fresh()->updated_at;

    $copy = copyOf($source)->fresh();

    expect($copy->id)->not->toBe($source->id)
        ->and($copy->sku)->toBe('COPY-1')
        ->and($copy->slug)->not->toBe($source->slug)
        ->and($copy->getTranslations('name'))->toMatchArray(['uz' => 'Manba mahsulot (nusxa)', 'ru' => 'Исходный товар (копия)'])
        ->and($copy->getTranslation('name', 'en', false))->toBe('')
        ->and($copy->getTranslation('short_description', 'ru', false))->toBe('Коротко')
        ->and($copy->getTranslation('description', 'uz', false))->toContain('Tavsif')
        ->and($copy->model)->toBe($source->model)
        ->and([$copy->category_id, $copy->brand_id, $copy->type, $copy->warranty_months, $copy->min_qty, $copy->stock_status, $copy->unit])
        ->toEqual([$source->category_id, $source->brand_id, $source->type, 24, 3, $source->stock_status, $source->unit])
        ->and($copy->getTranslation('meta_title', 'uz', false) ?? '')->toBe('')
        ->and($copy->getTranslation('meta_description', 'uz', false) ?? '')->toBe('');

    $keyValue = $copy->attributeValues()->whereNotNull('value_text')->first();
    expect($keyValue->is_key)->toBeTrue()
        ->and($keyValue->getTranslation('value_text', 'ru'))->toBe('Текст')
        ->and($copy->attributeValues()->whereNotNull('attribute_option_id')->count())->toBe(2)
        ->and(DB::table('product_attribute_skips')->where('product_id', $copy->id)->count())->toBe(1)
        ->and($copy->rollTypes()->orderBy('product_roll_type.sort')->pluck('roll_types.id')->all())
        ->toBe($source->rollTypes()->orderBy('product_roll_type.sort')->pluck('roll_types.id')->all());

    $faq = $copy->faqItems()->get();
    expect($faq->pluck('question')->map(fn ($q) => $q)->all())->toHaveCount(2)
        ->and($faq[0]->getTranslation('question', 'ru'))->toBe('Первый?')
        ->and($faq[0]->is_active)->toBeFalse()
        ->and($faq[1]->getTranslation('answer', 'en'))->toBe('Answer 2')
        ->and($source->faqItems()->count())->toBe(2)
        ->and($source->fresh()->updated_at->equalTo($before))->toBeTrue();
});

it('copies outbound relations to live products only, keeps type and sort, never inbound', function () {
    ['source' => $source, 'active' => $active, 'inactive' => $inactive] = copySource();

    $copy = copyOf($source);

    $rows = ProductRelation::query()->where('product_id', $copy->id)->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->firstWhere('related_id', $active->id)->type)->toBe(RelationType::Accessory)
        ->and($rows->firstWhere('related_id', $active->id)->sort)->toBe(2)
        ->and($rows->firstWhere('related_id', $inactive->id)->type)->toBe(RelationType::Consumable)
        ->and($rows->firstWhere('related_id', $inactive->id)->sort)->toBe(5)
        ->and(ProductRelation::query()->where('related_id', $copy->id)->count())->toBe(0);
});

it('does not copy media, documents, videos, news links, prices, the 1C link, IKPU, package code or badge', function () {
    ['source' => $source] = copySource();

    $copy = copyOf($source)->fresh();

    expect($copy->getMedia(Product::IMAGES))->toHaveCount(0)
        ->and($copy->documents()->count())->toBe(0)
        ->and($copy->videos()->count())->toBe(0)
        ->and($copy->newsPosts()->count())->toBe(0)
        ->and([$copy->price, $copy->price_dealer, $copy->price_wholesale, $copy->price_synced_at, $copy->price_sync_log_id])->each->toBeNull()
        ->and($copy->sale_mode)->toBe(SaleMode::Quote)
        ->and([$copy->ikpu_code, $copy->package_code, $copy->badge])->each->toBeNull()
        ->and($copy->is_active)->toBeFalse()
        ->and($copy->is_featured)->toBeFalse();

    $source = $source->fresh();
    expect($source->price)->toBe(100_000_00)->and($source->price_sync_log_id)->not->toBeNull()->and($source->getMedia(Product::IMAGES))->toHaveCount(1);
});

it('is a draft that is not on the public site', function () {
    ['source' => $source] = copySource();

    $copy = copyOf($source);

    expect(Product::query()->active()->whereKey($copy->id)->exists())->toBeFalse();
    $this->get('/uz/product/'.$copy->slug)->assertNotFound();
});

it('refuses a taken SKU, also of a trashed product, and creates nothing', function (bool $trashed) {
    ['source' => $source] = copySource();
    $other = Product::factory()->create(['sku' => 'TAKEN-1']);
    $trashed && $other->delete();
    $before = [Product::withTrashed()->count(), FaqItem::query()->count(), ProductRelation::query()->count(), ProductAttributeValue::query()->count()];

    expect(fn () => copyOf($source, ['sku' => 'taken-1']))->toThrow(ValidationException::class);

    expect([Product::withTrashed()->count(), FaqItem::query()->count(), ProductRelation::query()->count(), ProductAttributeValue::query()->count()])->toBe($before);
})->with([false, true]);

it('rolls everything back when an FAQ answer or the description breaks the brand rule', function (string $where) {
    ['source' => $source] = copySource();

    if ($where === 'faq') {
        $source->faqItems()->first()->setTranslation('answer', 'ru', 'Большие скидки')->saveQuietly();
    } else {
        $source->setTranslation('description', 'ru', '<p>Большие скидки</p>')->saveQuietly();
    }

    $before = [Product::withTrashed()->count(), FaqItem::query()->count(), ProductRelation::query()->count(), ProductAttributeValue::query()->count(), $source->fresh()->updated_at];

    expect(fn () => copyOf($source))->toThrow(ValidationException::class);

    expect([Product::withTrashed()->count(), FaqItem::query()->count(), ProductRelation::query()->count(), ProductAttributeValue::query()->count(), $source->fresh()->updated_at])->toEqual($before);
})->with(['faq', 'description']);

it('refuses a trashed source', function () {
    $source = Product::factory()->create();
    $source->delete();

    expect(fn () => copyOf($source))->toThrow(ValidationException::class);
    expect(Product::withTrashed()->count())->toBe(1);
});

it('computes has_missing_specs for the copy like SaveProduct does', function (string $case, bool $expected) {
    $category = Category::factory()->create();
    $required = Attribute::factory()->create(['code' => 'req_text']);
    $other = Attribute::factory()->create(['code' => 'req_other']);
    $category->attributes()->attach([$required->id => ['sort' => 1, 'is_required' => true], $other->id => ['sort' => 2, 'is_required' => false]]);
    $source = Product::factory()->inCategory($category)->create();

    if ($case !== 'empty') {
        ProductAttributeValue::factory()->create(['product_id' => $source->id, 'attribute_id' => $required->id, 'value_text' => ['uz' => 'Qiymat']]);
    }

    if ($case === 'skipped') {
        ProductAttributeSkip::factory()->create(['product_id' => $source->id, 'attribute_id' => $other->id]);
    }

    $copy = copyOf($source)->fresh();

    expect($copy->has_missing_specs)->toBe($expected);
})->with([
    'required spec empty' => ['empty', true],
    'fully specced' => ['full', false],
    'optional spec skipped, required filled' => ['skipped', false],
]);

it('refuses a source with more key specs than the limit and creates nothing', function () {
    $category = Category::factory()->create();
    $source = Product::factory()->inCategory($category)->create();

    foreach (range(1, Product::MAX_KEY_SPECS + 1) as $i) {
        $attribute = Attribute::factory()->create(['code' => "key_{$i}"]);
        $category->attributes()->attach($attribute->id, ['sort' => $i]);
        ProductAttributeValue::factory()->key()->create(['product_id' => $source->id, 'attribute_id' => $attribute->id, 'value_text' => ['uz' => "V{$i}"]]);
    }

    $before = [Product::withTrashed()->count(), ProductAttributeValue::query()->count()];

    expect(fn () => copyOf($source))->toThrow(ValidationException::class);

    expect([Product::withTrashed()->count(), ProductAttributeValue::query()->count()])->toBe($before);
});

it('keeps roll types of a per-label source in pivot order', function () {
    $small = RollType::factory()->size(500, '40.0')->create();
    $big = RollType::factory()->size(1000, '40.0')->create();
    $source = Product::factory()->perLabel()->withRollTypes([$big, $small])->create();

    $copy = copyOf($source);

    expect($copy->rollTypes()->orderBy('product_roll_type.sort')->pluck('roll_types.id')->all())->toBe([$big->id, $small->id])
        ->and($copy->rollTypes()->pluck('product_roll_type.sort', 'roll_types.id')->all())->toBe([$big->id => 1, $small->id => 2]);
});

it('gives a unit-priced source no roll types', function () {
    $source = Product::factory()->create();

    expect(copyOf($source)->rollTypes()->count())->toBe(0);
});
