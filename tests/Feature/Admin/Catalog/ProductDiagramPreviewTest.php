<?php

use App\Enums\ProductType;
use App\Enums\Role;
use App\Livewire\Admin\Products\Form;
use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\RollType;
use App\Support\Catalog\LabelDiagram;
use App\Support\Catalog\ProductDiagram;
use App\Support\Catalog\RollDiagram;
use Database\Seeders\CatalogSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * The admin-only "Auto-generated image" box (Products form, Images tab): preview() ignores
 * media, everything else matches for().
 */
beforeEach(function () {
    openSearchIndexing();
    $this->seed(CatalogSeeder::class);
    Storage::fake('public');
    Storage::fake('local');
});

function previewSpec(Product $product, string $code, mixed $value): ProductAttributeValue
{
    $attribute = Attribute::query()->where('code', $code)->first() ?? Attribute::factory()->number('mm')->create(['code' => $code]);

    if ($attribute->type->hasOptions()) {
        $option = $attribute->options()->get()->firstOrFail(fn (AttributeOption $o): bool => $o->getTranslation('value', 'uz', false) === (string) $value);

        return ProductAttributeValue::factory()->for($product)->option($option)->create();
    }

    $row = ProductAttributeValue::factory()->for($product)->create(['attribute_id' => $attribute->id]);
    DB::table('product_attribute_values')->where('id', $row->id)->update(['value_number' => $value, 'value_text' => null]);

    return $row;
}

function previewLabel(mixed $width = 100, mixed $height = 150): Product
{
    $category = Category::query()->where('slug', 'thermal-labels')->first() ?? Category::factory()->create(['slug' => 'thermal-labels']);
    $product = Product::factory()->perLabel(12)->inCategory($category)
        ->withRollTypes([RollType::query()->where('labels_per_roll', 500)->first() ?? RollType::factory()->size(500, '40.0')->create()])
        ->create(['slug' => 'preview-label-'.fake()->unique()->numerify('####')]);
    previewSpec($product, 'label_width', $width);
    previewSpec($product, 'label_height', $height);

    return $product;
}

function previewRibbon(string $colour = 'Qora'): Product
{
    $product = Product::factory()->consumable()->inCategory(Category::query()->where('slug', 'ribbons')->firstOrFail())
        ->create(['slug' => 'preview-ribbon-'.fake()->unique()->numerify('####')]);

    foreach (['ribbon_type' => 'Resin (smola)', 'ribbon_width' => 110, 'ribbon_length' => 300, 'ink_side' => 'OUT', 'core_diameter' => '25', 'ribbon_colour' => $colour] as $code => $value) {
        previewSpec($product, $code, $value);
    }

    return $product;
}

function withPhoto(Product $product): Product
{
    $product->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaCollection(Product::IMAGES);

    return $product;
}

function freshForPreview(Product $product, array $with): Product
{
    return Product::query()->with($with)->findOrFail($product->id);
}

describe('model preview()', function () {
    it('builds with media where for() returns null', function () {
        $label = withPhoto(previewLabel());
        $label = freshForPreview($label, ['media', 'labelSizeValues.attribute', 'activeRollTypes']);
        $ribbon = withPhoto(previewRibbon());
        $ribbon = freshForPreview($ribbon, ['media', 'rollSpecValues.attribute', 'rollSpecValues.option']);

        expect(LabelDiagram::for($label))->toBeNull()
            ->and(LabelDiagram::preview($label))->toBeInstanceOf(LabelDiagram::class)
            ->and(ProductDiagram::for($label))->toBeNull()
            ->and(ProductDiagram::preview($label))->toBeInstanceOf(LabelDiagram::class)
            ->and(RollDiagram::for($ribbon))->toBeNull()
            ->and(RollDiagram::preview($ribbon))->toBeInstanceOf(RollDiagram::class)
            ->and(ProductDiagram::preview($ribbon))->toBeInstanceOf(RollDiagram::class);
    });

    it('returns null with zero queries when relations are not loaded', function () {
        $label = Product::query()->findOrFail(previewLabel()->id);
        $ribbon = Product::query()->findOrFail(previewRibbon()->id);

        DB::enableQueryLog();

        expect(ProductDiagram::preview($label))->toBeNull()
            ->and(ProductDiagram::preview($ribbon))->toBeNull()
            ->and(DB::getQueryLog())->toBe([]);
    });

    it('gives null for a receipt printer and a hardware product', function () {
        $printer = Product::factory()->inCategory(Category::query()->where('slug', 'receipt-printers')->firstOrFail())->create();
        previewSpec($printer, 'paper_width', '80');
        previewSpec($printer, 'paper_length', 80);
        $hardware = Product::factory()->create(['type' => ProductType::Hardware]);
        previewSpec($hardware, 'ribbon_width', 110);
        previewSpec($hardware, 'ribbon_length', 300);

        foreach ([$printer, $hardware] as $product) {
            expect(ProductDiagram::preview(freshForPreview($product, ['media', 'attributeValues.attribute', 'attributeValues.option'])))->toBeNull();
        }
    });

    it('gives null for invalid specs', function (mixed $width) {
        $label = previewLabel($width, 150);

        expect(ProductDiagram::preview(freshForPreview($label, ['labelSizeValues.attribute', 'activeRollTypes'])))->toBeNull();
    })->with(['negative' => [-5], 'too small' => [4], 'too big' => [301]]);
});

describe('admin form box', function () {
    it('shows the box and note for each role on a label product with a photo, and 403 without products.view', function (Role $role) {
        $product = withPhoto(previewLabel());
        $this->actingAs(staffUser($role));

        $this->get(route('admin.products.edit', $product))->assertOk()
            ->assertSee('data-label-diagram', false)
            ->assertSee(__('admin.products.diagram_preview.title'))
            ->assertSee(__('admin.products.diagram_preview.note'))
            ->assertSee('wire:key="diagram-preview-'.$product->id.'"', false);
    })->with([[Role::Admin], [Role::ContentManager], [Role::SalesManager], [Role::ServiceEngineer]]);

    it('forbids a user without products.view', function () {
        $user = staffUser(Role::SalesManager);
        $user->roles->each(fn ($role) => $role->revokePermissionTo('products.view'));
        $user->forgetCachedPermissions();
        $this->actingAs($user);

        $this->get(route('admin.products.edit', previewLabel()))->assertForbidden();
    });

    it('has the note in all three languages with no price wording', function () {
        foreach (['uz', 'ru', 'en'] as $locale) {
            $note = __('admin.products.diagram_preview.note', [], $locale);

            expect($note)->not->toBe('admin.products.diagram_preview.note')->and(mb_strtolower($note))->not->toMatch('/price|цен|narx|arzon|chegirma/u');
        }

        expect(__('admin.products.diagram_preview.note', [], 'uz'))->toContain('ʻ')->not->toContain("'");
    });

    it('hides the box on create and when no diagram can be built', function () {
        $this->actingAs(staffUser());

        Livewire::test(Form::class)->assertDontSee('data-diagram-preview', false)->assertSet('product', null)->assertDontSee(__('admin.products.diagram_preview.title'));

        $hardware = Product::factory()->create(['type' => ProductType::Hardware]);
        Livewire::test(Form::class, ['product' => $hardware])->assertDontSee(__('admin.products.diagram_preview.title'));

        $label = previewLabel(4, 150);
        Livewire::test(Form::class, ['product' => $label])->assertDontSee(__('admin.products.diagram_preview.title'));
    });

    it('shows a ribbon diagram too', function () {
        $this->actingAs(staffUser());

        Livewire::test(Form::class, ['product' => withPhoto(previewRibbon())])->assertSee('data-roll-diagram', false)->assertSee(__('admin.products.diagram_preview.note'));
    });

    it('keeps the preview on saved data until save', function () {
        $this->actingAs(staffUser());
        $product = previewLabel(100, 150);
        $component = Livewire::test(Form::class, ['product' => $product]);
        $before = $component->instance()->diagramPreview->width;

        $specIndex = collect($component->get('form.specs'))->search(fn (array $row): bool => (int) $row['attribute_id'] === Attribute::query()->where('code', 'label_width')->value('id'));
        expect($specIndex)->not->toBeFalse();

        $component->set("form.specs.{$specIndex}.number", '60');
        expect($component->instance()->diagramPreview->width)->toBe($before)->toBe(100.0);

        $component->call('save')->assertHasNoErrors();
        expect($component->instance()->diagramPreview->width)->toBe(60.0);
    });

    it('loads no diagram relation on the locked product and runs no value query for hardware', function () {
        $this->actingAs(staffUser());
        $label = previewLabel();
        $component = Livewire::test(Form::class, ['product' => $label]);

        foreach (['labelSizeValues', 'rollSpecValues', 'activeRollTypes', 'attributeValues'] as $relation) {
            expect($component->instance()->product->relationLoaded($relation))->toBeFalse($relation);
        }

        $hardware = Product::factory()->create(['type' => ProductType::Hardware]);
        $instance = Livewire::test(Form::class, ['product' => $hardware])->instance();
        unset($instance->diagramPreview);
        DB::enableQueryLog();

        expect($instance->diagramPreview)->toBeNull()
            ->and(collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'product_attribute_values')))->toBeEmpty();
    });

    it('stays within a small fixed query count for label and ribbon products', function (string $kind) {
        $this->actingAs(staffUser());
        $product = $kind === 'label' ? previewLabel() : previewRibbon();
        $instance = Livewire::test(Form::class, ['product' => $product])->instance();
        unset($instance->diagramPreview);
        DB::flushQueryLog();
        DB::enableQueryLog();

        expect($instance->diagramPreview)->not->toBeNull()
            ->and(count(DB::getQueryLog()))->toBeLessThanOrEqual(6);
    })->with(['label', 'ribbon']);

    it('never renders option text or an invalid hex into the svg and normalizes a valid one', function () {
        $this->actingAs(staffUser());
        $option = fn () => Attribute::query()->where('code', 'ribbon_colour')->firstOrFail()->options()->get()
            ->firstOrFail(fn (AttributeOption $o): bool => $o->getTranslation('value', 'uz', false) === 'Qora');
        $product = previewRibbon();

        $optionId = $option()->id;
        $option()->forceFill(['value' => ['uz' => '<b>x</b>', 'ru' => '<b>x</b>', 'en' => '<b>x</b>'], 'hex_color' => '#abcdef'])->save();
        $svg = function () use ($product): string {
            preg_match('#<svg[^>]*data-roll-diagram.*?</svg>#s', $this->get(route('admin.products.edit', $product))->assertOk()->getContent(), $m);

            return $m[0] ?? '';
        };

        $valid = $svg();
        expect($valid)->not->toBe('')->not->toContain('<b>')->not->toContain('style=')->toContain('fill="#ABCDEF"')->not->toContain('#abcdef');

        DB::table('attribute_options')->where('id', $optionId)->update(['hex_color' => '#000;x']);
        expect($svg())->not->toContain('#000;x')->not->toContain('<b>');
    });
});

describe('site and TMA never use preview()', function () {
    it('shows no diagram for a product with a photo on the site and in the TMA', function () {
        $this->withoutVite();
        tmaEnabled();
        tmaSignIn(tmaUser());
        $label = withPhoto(previewLabel());
        $ribbon = withPhoto(previewRibbon());

        foreach ([$label, $ribbon] as $product) {
            expect($this->get("/en/product/{$product->slug}")->assertOk()->getContent())->not->toContain('data-label-diagram')->not->toContain('data-roll-diagram')
                ->and($this->get("/tma/uz/p/{$product->slug}")->assertOk()->getContent())->not->toContain('data-diagram')->not->toContain('data-label-diagram')->not->toContain('data-roll-diagram');
        }
    });

    it('calls preview() only from the Products form', function () {
        $hits = collect(['app', 'resources'])
            ->flatMap(fn (string $dir) => iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS))))
            ->filter(fn (SplFileInfo $file): bool => $file->isFile() && preg_match('/\.php$/', $file->getFilename()) === 1)
            ->filter(fn (SplFileInfo $file): bool => preg_match('/Diagram::preview\(/', file_get_contents($file->getPathname())) === 1)
            ->map(fn (SplFileInfo $file): string => str_replace(base_path().'/', '', $file->getPathname()))
            ->sort()->values()->all();

        expect($hits)->toBe(['app/Livewire/Admin/Products/Form.php', 'app/Support/Catalog/ProductDiagram.php']);
    });
});
