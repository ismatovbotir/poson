<?php

use App\Actions\Catalog\PublishSeededProducts;
use App\Enums\McpCallStatus;
use App\Enums\ProductPricingUnit;
use App\Enums\Role;
use App\Enums\StockStatus;
use App\Mcp\Servers\AdminServer;
use App\Mcp\Tools\Catalog\CreateProductDraft;
use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Brand;
use App\Models\Category;
use App\Models\McpCall;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\Setting;
use Database\Seeders\ProductsSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

require_once __DIR__.'/../../../Helpers/McpScan.php';

beforeEach(function () {
    mcpTelegramConfigured();
    config(['app.url' => 'https://poson.test']);
});

/**
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function productArgs(Category $category, array $over = []): array
{
    return [
        'type' => 'hardware',
        'category_id' => $category->id,
        'sku' => 'MCP-100',
        'name' => ['uz' => 'Shtrix-kod skaneri'],
        ...$over,
    ];
}

function assignAttribute(Category $category, Attribute $attribute, bool $required = false): void
{
    DB::table('category_attribute')->insert([
        'category_id' => $category->id,
        'attribute_id' => $attribute->id,
        'is_required' => $required,
        'is_filterable' => false,
        'sort' => 1,
    ]);
}

function createProduct(string $plain, array $args)
{
    return mcpCallTool($plain, 'create_product_draft', $args);
}

it('creates an inactive priceless draft with forced values, even as Admin (M3-S3)', function () {
    [$ceo] = mcpHolder($plain = fakeMcpTokenValue());
    $category = Category::factory()->create();
    $brand = Brand::factory()->create();

    $content = createProduct($plain, productArgs($category, ['brand_id' => $brand->id, 'sku' => ' mcp-100 ', 'model' => 'ZD421', 'warranty_months' => 12, 'min_qty' => 2, 'unit' => 'box']))
        ->assertOk()->json('result.structuredContent');

    $product = Product::query()->sole();
    expect($content)->toHaveKeys(['id', 'sku', 'has_missing_specs', 'missing_required', 'issues', 'admin_url', 'warnings'])
        ->and($content)->not->toHaveKeys(['price', 'price_dealer', 'price_wholesale'])
        ->and($content['admin_url'])->toBe('https://poson.test/admin/products/'.$product->id.'/edit')
        ->and($product)
        ->sku->toBe('MCP-100')->is_active->toBeFalse()->is_featured->toBeFalse()->badge->toBeNull()
        ->price->toBeNull()->price_dealer->toBeNull()->price_wholesale->toBeNull()
        ->stock_status->toBe(StockStatus::OnOrder)->pricing_unit->toBe(ProductPricingUnit::Unit)
        ->ikpu_code->toBeNull()->package_code->toBeNull()->min_qty->toBe(2)->sale_mode->value->toBe('quote')
        ->and($product->rollTypes()->count())->toBe(0)
        ->and(McpCall::query()->sole())->is_write->toBeTrue()->is_publish->toBeFalse()->before->toBeNull()
        ->subject_type->toBe('product')->subject_id->toBe($product->id);
});

it('rejects arguments outside the contract without echoing them (M3-S2)', function (string $key) {
    mcpHolder($plain = fakeMcpTokenValue());
    $category = Category::factory()->create();

    $response = createProduct($plain, productArgs($category, [$key => 'SENTINEL-VALUE-9']));

    expect($response->json('result.isError'))->toBeTrue()->and($response->getContent())->not->toContain('SENTINEL-VALUE-9')
        ->and(Product::query()->count())->toBe(0);
})->with(['id', 'status', 'slug', 'author_id', 'sort', 'faqable_type', 'template', 'is_system', 'needs_review', 'is_active', 'price', 'price_dealer', 'price_wholesale', 'pricing_unit', 'ikpu_code', 'package_code', 'roll_type_ids', 'is_featured', 'badge', 'stock_status', 'images', 'documents']);

it('rejects unknown keys and other types inside spec items', function (array $item) {
    mcpHolder($plain = fakeMcpTokenValue());
    $category = Category::factory()->create();
    assignAttribute($category, $attribute = Attribute::factory()->create());

    $response = createProduct($plain, productArgs($category, ['specs' => [['code' => $attribute->code, ...$item]]]));

    expect($response->json('result.isError'))->toBeTrue()->and($response->getContent())->not->toContain('SENTINEL-VALUE-9')
        ->and(Product::query()->count())->toBe(0);
})->with([
    'price' => [['price' => 'SENTINEL-VALUE-9']],
    'attribute_id' => [['attribute_id' => 5]],
    'text key' => [['text' => ['uz' => 'SENTINEL-VALUE-9']]],
    'sort' => [['sort' => 3]],
]);

it('refuses an existing SKU in any state or case and leaves the existing product byte-identical (M3-S4)', function (string $state, string $sku) {
    mcpHolder($plain = fakeMcpTokenValue());
    $category = Category::factory()->create();
    $attribute = Attribute::factory()->number()->create();
    assignAttribute($category, $attribute);
    $existing = Product::factory()->inCategory($category)->withRollTypes()->create(['sku' => 'TAKEN-1']);
    ProductAttributeValue::query()->create(['product_id' => $existing->id, 'attribute_id' => $attribute->id, 'value_number' => '5', 'is_key' => false, 'sort' => 1]);
    match ($state) {
        'inactive' => $existing->forceFill(['is_active' => false])->saveQuietly(),
        'trashed' => $existing->delete(),
        default => null,
    };
    $snapshot = fn (): array => [
        Product::withTrashed()->find($existing->id)->getAttributes(),
        ProductAttributeValue::query()->where('product_id', $existing->id)->get()->toArray(),
        DB::table('product_roll_type')->where('product_id', $existing->id)->get()->toArray(),
    ];
    $before = $snapshot();

    $response = createProduct($plain, productArgs($category, ['sku' => $sku, 'specs' => [['code' => $attribute->code, 'value' => 99]]]));

    expect($response->json('result.isError'))->toBeTrue()->and($response->getContent())->toContain('SKU already exists.')
        ->and($response->getContent())->not->toContain($existing->getTranslation('name', 'uz'))->not->toContain('TAKEN-1')
        ->and(Product::withTrashed()->count())->toBe(1)
        ->and($snapshot())->toEqual($before);
})->with([
    'active' => ['active', 'TAKEN-1'],
    'inactive' => ['inactive', 'TAKEN-1'],
    'trashed' => ['trashed', 'TAKEN-1'],
    'lower case' => ['active', 'taken-1'],
    'padded' => ['active', '  Taken-1 '],
]);

it('refuses a SKU recorded by the product seeder', function () {
    mcpHolder($plain = fakeMcpTokenValue());
    $category = Category::factory()->create();
    Setting::query()->create(['key' => ProductsSeeder::RECORD_KEY, 'group' => 'system', 'value' => ['skus' => ['SEED-1']]]);

    $response = createProduct($plain, productArgs($category, ['sku' => 'seed-1']));

    expect($response->json('result.isError'))->toBeTrue()->and($response->getContent())->toContain('SKU already exists.')
        ->and(Product::query()->count())->toBe(0);
});

it('refuses the same SKU sent again within 10 minutes', function () {
    mcpHolder($plain = fakeMcpTokenValue());
    $category = Category::factory()->create();

    createProduct($plain, productArgs($category))->assertOk();
    $this->travel(5)->minutes();
    $again = createProduct($plain, productArgs($category, ['name' => ['uz' => 'Boshqa nom']]));

    expect($again->json('result.isError'))->toBeTrue()->and($again->getContent())->toContain('SKU already exists.')
        ->and(Product::query()->count())->toBe(1)->and(Product::query()->sole()->getTranslation('name', 'uz'))->toBe('Shtrix-kod skaneri');
});

it('maps specs by code and option label in any locale, case-insensitively', function () {
    mcpHolder($plain = fakeMcpTokenValue());
    $category = Category::factory()->create();
    $text = Attribute::factory()->create(['code' => 'color']);
    $number = Attribute::factory()->number()->create(['code' => 'weight']);
    $bool = Attribute::factory()->boolean()->create(['code' => 'wireless']);
    $select = Attribute::factory()->select(['USB', 'RS232'])->create(['code' => 'port']);
    $multi = Attribute::factory()->multiselect(['QR Code', 'DataMatrix'])->create(['code' => 'codes']);
    $select->options()->first()->update(['value' => ['uz' => 'USB', 'ru' => 'ЮСБ', 'en' => 'USB']]);
    foreach ([$text, $number, $bool, $select, $multi] as $attribute) {
        assignAttribute($category, $attribute);
    }

    createProduct($plain, productArgs($category, ['specs' => [
        ['code' => 'color', 'value' => ['uz' => 'Qora', 'en' => 'Black'], 'is_key' => true],
        ['code' => 'weight', 'value' => '1,5'],
        ['code' => 'wireless', 'value' => true],
        ['code' => 'port', 'option' => ' юсб '],
        ['code' => 'codes', 'options' => ['qr code', 'DATAMATRIX']],
    ]]))->assertOk();

    $values = ProductAttributeValue::query()->where('product_id', Product::query()->sole()->id)->get()->groupBy('attribute_id');
    expect($values)->toHaveCount(5)
        ->and($values[$text->id][0]->getTranslation('value_text', 'en'))->toBe('Black')->and($values[$text->id][0]->is_key)->toBeTrue()
        ->and((float) $values[$number->id][0]->value_number)->toBe(1.5)
        ->and($values[$bool->id][0]->value_bool)->toBeTrue()
        ->and($values[$select->id][0]->attribute_option_id)->toBe($select->options()->first()->id)
        ->and($values[$multi->id])->toHaveCount(2);
});

it('lists valid options for an unknown option and creates nothing (M3-S12)', function () {
    mcpHolder($plain = fakeMcpTokenValue());
    $category = Category::factory()->create();
    $select = Attribute::factory()->select(['USB', 'RS232'])->create(['code' => 'port']);
    assignAttribute($category, $select);
    $attributes = Attribute::query()->count();
    $options = AttributeOption::query()->count();

    $response = createProduct($plain, productArgs($category, ['specs' => [['code' => 'port', 'option' => 'SENTINEL-OPTION-77']]]));

    expect($response->json('result.isError'))->toBeTrue()->and($response->getContent())->toContain('USB')->toContain('RS232')
        ->not->toContain('SENTINEL-OPTION-77')
        ->and(Attribute::query()->count())->toBe($attributes)->and(AttributeOption::query()->count())->toBe($options)
        ->and(Product::query()->count())->toBe(0);
});

it('caps the listed options at 50 and cleans them', function () {
    mcpHolder($plain = fakeMcpTokenValue());
    $category = Category::factory()->create();
    $select = Attribute::factory()->select(array_map(fn (int $i): string => 'Opt'.$i, range(1, 70)))->create(['code' => 'port']);
    assignAttribute($category, $select);

    $content = createProduct($plain, productArgs($category, ['specs' => [['code' => 'port', 'option' => 'nope']]]))->getContent();

    expect($content)->toContain('Opt50')->not->toContain('Opt51');
});

it('refuses a spec code from another category, an unknown code and a duplicate', function (string $code) {
    mcpHolder($plain = fakeMcpTokenValue());
    $category = Category::factory()->create();
    $other = Category::factory()->create();
    assignAttribute($category, Attribute::factory()->create(['code' => 'color']));
    assignAttribute($other, Attribute::factory()->create(['code' => 'foreign']));

    $specs = $code === 'dup' ? [['code' => 'color', 'value' => ['uz' => 'A']], ['code' => 'color', 'value' => ['uz' => 'B']]] : [['code' => $code, 'value' => ['uz' => 'A']]];
    $response = createProduct($plain, productArgs($category, ['specs' => $specs]));

    expect($response->json('result.isError'))->toBeTrue()->and(Product::query()->count())->toBe(0);
    if ($code !== 'dup') {
        expect($response->getContent())->toContain('color')->not->toContain('foreign');
    }
})->with(['foreign', 'does_not_exist', 'dup']);

it('returns validation errors, not server errors, for wrongly typed spec values', function (string $code, array $item) {
    mcpHolder($plain = fakeMcpTokenValue());
    $category = Category::factory()->create();
    assignAttribute($category, Attribute::factory()->create(['code' => 'txt']));
    assignAttribute($category, Attribute::factory()->number()->create(['code' => 'num']));
    assignAttribute($category, Attribute::factory()->boolean()->create(['code' => 'flag']));
    assignAttribute($category, Attribute::factory()->select(['A'])->create(['code' => 'sel']));
    assignAttribute($category, Attribute::factory()->multiselect(['A'])->create(['code' => 'mul']));

    $response = createProduct($plain, productArgs($category, ['specs' => [['code' => $code, ...$item]]]));

    expect($response->json('result.isError'))->toBeTrue()->and($response->getContent())->not->toContain('server error')
        ->and(McpCall::query()->latest('id')->first()->status)->toBe(McpCallStatus::Invalid);
})->with([
    'text as string' => ['txt', ['value' => 'plain']],
    'text unknown locale' => ['txt', ['value' => ['de' => 'x']]],
    'text without uz' => ['txt', ['value' => ['ru' => 'x']]],
    'number as text' => ['num', ['value' => 'abc']],
    'number as bool' => ['num', ['value' => true]],
    'number as array' => ['num', ['value' => [1]]],
    'bool as string' => ['flag', ['value' => 'yes']],
    'bool as number' => ['flag', ['value' => 1]],
    'select as list' => ['sel', ['option' => ['A']]],
    'multiselect as string' => ['mul', ['options' => 'A']],
    'multiselect with numbers' => ['mul', ['options' => [1]]],
    'multiselect as object' => ['mul', ['options' => ['x' => 'A']]],
]);

it('allows at most 60 specs', function () {
    mcpHolder($plain = fakeMcpTokenValue());
    $category = Category::factory()->create();
    $specs = array_map(fn (int $i): array => ['code' => 'c'.$i], range(1, 61));

    expect(createProduct($plain, productArgs($category, ['specs' => $specs]))->json('result.isError'))->toBeTrue();
});

it('reports missing required specs on the draft', function () {
    mcpHolder($plain = fakeMcpTokenValue());
    $category = Category::factory()->create();
    assignAttribute($category, Attribute::factory()->number()->create(['code' => 'dpi']), required: true);

    $content = createProduct($plain, productArgs($category))->assertOk()->json('result.structuredContent');

    expect($content['has_missing_specs'])->toBeTrue()->and($content['missing_required'])->toBe(['dpi'])
        ->and(Product::query()->sole()->has_missing_specs)->toBeTrue()
        ->and(collect($content['warnings'])->pluck('code')->all())->toContain('missing_required_specs');
});

it('rejects banned wording in every text field and locale (M3-S11)', function (string $field, string $locale, string $word) {
    mcpHolder($plain = fakeMcpTokenValue());
    $category = Category::factory()->create();
    $args = match ($field) {
        'model' => ['model' => "Zebra $word"],
        'description' => ['description' => [$locale => "<p>Yaxshi $word</p>"]],
        default => [$field => [$locale => "Yaxshi $word"]],
    };

    expect(createProduct($plain, productArgs($category, $args))->json('result.isError'))->toBeTrue()
        ->and(Product::query()->count())->toBe(0);
})->with(function () {
    foreach (['name', 'short_description', 'description', 'meta_title', 'meta_description'] as $field) {
        foreach (['uz', 'ru', 'en'] as $locale) {
            foreach (['arzon', 'дешевле', 'cheapest', 'chegirma'] as $word) {
                yield "$field $locale $word" => [$field, $locale, $word];
            }
        }
    }
    foreach (['arzon', 'cheapest', 'chegirma', 'дешевле'] as $word) {
        yield "model $word" => ['model', 'uz', $word];
    }
});

it('rejects banned wording in spec text values', function () {
    mcpHolder($plain = fakeMcpTokenValue());
    $category = Category::factory()->create();
    assignAttribute($category, Attribute::factory()->create(['code' => 'note']));

    expect(createProduct($plain, productArgs($category, ['specs' => [['code' => 'note', 'value' => ['uz' => 'Ok', 'ru' => 'дешевле всех']]]]))->json('result.isError'))->toBeTrue()
        ->and(Product::query()->count())->toBe(0);
});

it('validates enums, ids and lengths', function (array $over) {
    mcpHolder($plain = fakeMcpTokenValue());
    $category = Category::factory()->create();

    expect(createProduct($plain, productArgs($category, $over))->json('result.isError'))->toBeTrue()->and(Product::query()->count())->toBe(0);
})->with([
    'roll unit' => [['unit' => 'roll']],
    'bad type' => [['type' => 'gadget']],
    'bad sku' => [['sku' => 'bad;sku']],
    'long sku' => [['sku' => str_repeat('A', 65)]],
    'missing category' => [['category_id' => 999999]],
    'missing brand' => [['brand_id' => 999999]],
    'min_qty 0' => [['min_qty' => 0]],
    'warranty 601' => [['warranty_months' => 601]],
    'sale_mode is not an input' => [['sale_mode' => 'cart']],
    'locale' => [['name' => ['uz' => 'A', 'de' => 'B']]],
    'no uz' => [['name' => ['ru' => 'A']]],
]);

it('sanitizes the description', function () {
    mcpHolder($plain = fakeMcpTokenValue());
    $category = Category::factory()->create();

    createProduct($plain, productArgs($category, ['description' => ['uz' => '<p onclick="x()">Matn</p><script>alert(1)</script><a href="javascript:alert(2)">j</a>']]))->assertOk();

    expect(Product::query()->sole()->getTranslation('description', 'uz'))->not->toContain('script')->not->toContain('onclick')->not->toContain('javascript:');
});

it('restricts a service engineer to software and service types, per the role matrix', function () {
    $category = Category::factory()->create();
    $engineer = AdminServer::actingAs(staffUser(Role::ServiceEngineer));

    $engineer->tool(CreateProductDraft::class, productArgs($category, ['type' => 'hardware']))->assertHasErrors(['You may not create products of this type.']);
    $engineer->tool(CreateProductDraft::class, productArgs($category, ['type' => 'service', 'sku' => 'SRV-1']))->assertOk();
    $engineer->tool(CreateProductDraft::class, productArgs($category, ['type' => 'software', 'sku' => 'SW-1']))->assertOk();

    expect(Product::query()->pluck('type')->map->value->sort()->values()->all())->toBe(['service', 'software']);
});

it('is registered per role and is not read-only (M3-S16)', function (Role $role, bool $allowed) {
    $category = Category::factory()->create();
    $server = AdminServer::actingAs(staffUser($role));
    $args = productArgs($category, ['type' => 'software']);

    $allowed ? $server->tool(CreateProductDraft::class, $args)->assertOk() : $server->tool(CreateProductDraft::class, $args)->assertNotRegistered();
    expect((new ReflectionClass(CreateProductDraft::class))->getAttributes(IsReadOnly::class))->toBeEmpty()
        ->and(app(CreateProductDraft::class)->description())->toContain('you cannot edit existing items');
})->with(function () {
    $matrix = [];
    foreach (Role::cases() as $role) {
        $matrix[$role->value] = [$role, in_array($role, [Role::Admin, Role::ContentManager, Role::ServiceEngineer], true)];
    }

    return $matrix;
});

it('keeps an MCP draft inactive when the launch publisher runs (it only touches SKUs the seeder recorded)', function () {
    mcpHolder($plain = fakeMcpTokenValue());
    $category = Category::factory()->create();
    createProduct($plain, productArgs($category))->assertOk();
    $draft = Product::query()->sole();
    $seeded = Product::factory()->inCategory($category)->inactive()->create(['sku' => 'OTHER-1']);
    Setting::query()->create(['key' => ProductsSeeder::RECORD_KEY, 'group' => 'system', 'value' => ['skus' => ['OTHER-1']]]);

    app(PublishSeededProducts::class)->handle();

    expect($draft->fresh()->is_active)->toBeFalse();
    // The SKU of a draft can never be recorded afterwards by creating it through MCP: recorded SKUs are refused.
    expect(createProduct($plain, productArgs($category, ['sku' => 'other-1']))->json('result.isError'))->toBeTrue();
});
