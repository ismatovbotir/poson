<?php

use App\Actions\Catalog\SaveCategory;
use App\Enums\Role;
use App\Livewire\Admin\Categories\Form;
use App\Livewire\Admin\Categories\Index;
use App\Models\Attribute;
use App\Models\Category;
use App\Support\Catalog\CategoryTree;
use Database\Seeders\CatalogSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * @return array{0: Category, 1: Category, 2: Category}
 */
function categoryChain(): array
{
    $root = Category::factory()->named(['uz' => 'Skanerlar'])->create(['sort' => 1]);
    $child = Category::factory()->child($root)->named(['uz' => 'Qoʻl skanerlari'])->create();
    $grandchild = Category::factory()->child($child)->named(['uz' => 'Simsiz'])->create();

    return [$root, $child, $grandchild];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function categoryData(array $overrides = []): array
{
    return array_replace([
        'parent_id' => null,
        'name' => ['uz' => 'Printerlar', 'ru' => 'Принтеры', 'en' => 'Printers'],
        'description' => ['uz' => '', 'ru' => '', 'en' => ''],
        'meta_title' => ['uz' => '', 'ru' => '', 'en' => ''],
        'meta_description' => ['uz' => '', 'ru' => '', 'en' => ''],
        'slug' => '',
        'icon' => null,
        'sort' => null,
        'is_active' => true,
    ], $overrides);
}

describe('index', function () {
    it('shows the tree without lazy loading', function () {
        [$root, $child] = categoryChain();
        Category::factory()->count(2)->create();
        $root->attributes()->attach(Attribute::factory()->create(), ['sort' => 1]);

        $this->actingAs(staffUser())
            ->get(route('admin.categories.index'))
            ->assertOk()
            ->assertSeeLivewire(Index::class)
            ->assertSeeInOrder(['Skanerlar', 'Qoʻl skanerlari', 'Simsiz'])
            ->assertSee('wire:sort="reorder"', false);
    });

    it('filters by search and status into a flat list with paths', function () {
        categoryChain();
        Category::factory()->inactive()->named(['uz' => 'Arxiv'])->create();

        Livewire::actingAs(staffUser())
            ->test(Index::class)
            ->set('search', 'simsiz')
            ->assertSee('Skanerlar › Qoʻl skanerlari › Simsiz')
            ->assertDontSee('Arxiv')
            ->set('search', '')
            ->set('status', 'inactive')
            ->assertSee('Arxiv')
            ->assertDontSee('Simsiz');
    });

    it('matches Uzbek names typed with a plain apostrophe', function () {
        categoryChain();

        Livewire::actingAs(staffUser())
            ->test(Index::class)
            ->set('search', "qo'l")
            ->assertSee('Qoʻl skanerlari');
    });

    it('reorders siblings by drag and by arrows', function () {
        $first = Category::factory()->create(['sort' => 1]);
        $second = Category::factory()->create(['sort' => 2]);
        $third = Category::factory()->create(['sort' => 3]);

        $component = Livewire::actingAs(staffUser(Role::ContentManager))->test(Index::class);

        $component->call('reorder', $third->id, 0);
        expect(Category::query()->orderBy('sort')->pluck('id')->all())->toBe([$third->id, $first->id, $second->id])
            ->and(array_column(CategoryTree::children(null), 'id'))->toBe([$third->id, $first->id, $second->id]);

        $component->call('move', $third->id, 1);
        expect(Category::query()->orderBy('sort')->pluck('id')->all())->toBe([$first->id, $third->id, $second->id]);
    });

    it('blocks deleting a category with subcategories', function () {
        [$root] = categoryChain();

        Livewire::actingAs(staffUser())
            ->test(Index::class)
            ->call('delete', $root->id)
            ->assertDispatched('toast', type: 'error', body: __('admin.categories.errors.has_children'));

        expect($root->fresh())->not->toBeNull();
    });

    it('deletes a leaf category with its attribute assignments', function () {
        $category = Category::factory()->create();
        $category->attributes()->attach(Attribute::factory()->create(), ['sort' => 1]);

        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Index::class)
            ->call('delete', $category->id)
            ->assertDispatched('toast');

        expect(Category::query()->count())->toBe(0)
            ->and(DB::table('category_attribute')->count())->toBe(0);
    });
});

describe('form', function () {
    it('creates a category with a slug from the English name and a clean description', function () {
        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Form::class)
            ->set('form.name.uz', 'Yorliq printerlari')
            ->set('form.name.ru', 'Принтеры этикеток')
            ->set('form.name.en', 'Label printers')
            ->set('form.description.uz', '<p>Matn<script>alert(1)</script></p>')
            ->set('form.icon', 'print')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $category = Category::query()->sole();

        expect($category->slug)->toBe('label-printers')
            ->and($category->getTranslation('description', 'uz'))->toBe('<p>Matn</p>')
            ->and($category->getRawOriginal('description'))->not->toContain('"ru"')
            ->and($category->getRawOriginal('meta_title'))->toBeNull()
            ->and($category->icon)->toBe('print')
            ->and($category->sort)->toBe(1);
    });

    it('builds the slug from the uz name when there is no English name', function () {
        Livewire::actingAs(staffUser())
            ->test(Form::class)
            ->set('form.name.uz', 'Oʻlchov taʼminoti')
            ->call('save')
            ->assertHasNoErrors();

        expect(Category::query()->sole()->slug)->toBe('olchov-taminoti');
    });

    it('requires the uz name and a unique slug', function () {
        Category::factory()->create(['slug' => 'taken']);

        Livewire::actingAs(staffUser())
            ->test(Form::class)
            ->set('form.slug', 'Taken')
            ->call('save')
            ->assertHasErrors(['form.name.uz' => 'required', 'form.slug' => 'unique']);
    });

    it('updates a category and shows a toast', function () {
        $category = Category::factory()->create(['slug' => 'old-slug']);

        Livewire::actingAs(staffUser())
            ->test(Form::class, ['category' => $category])
            ->assertSet('form.slug', 'old-slug')
            ->set('form.name.ru', 'Новое')
            ->set('form.slug', 'new-slug')
            ->set('form.is_active', false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        $category->refresh();

        expect($category->getTranslation('name', 'ru'))->toBe('Новое')
            ->and($category->slug)->toBe('new-slug')
            ->and($category->is_active)->toBeFalse();
    });

    it('rejects a parent that would create a cycle', function () {
        [$root, $child] = categoryChain();

        Livewire::actingAs(staffUser())
            ->test(Form::class, ['category' => $root])
            ->assertDontSee('value="'.$child->id.'"', false)
            ->set('form.parent_id', (string) $child->id)
            ->call('save')
            ->assertHasErrors('form.parent_id');

        expect(fn () => app(SaveCategory::class)->handle($root, categoryData(['parent_id' => $root->id])))
            ->toThrow(ValidationException::class);
        expect($root->fresh()->parent_id)->toBeNull();
    });

    it('rejects a fourth level', function () {
        [$root, $child, $grandchild] = categoryChain();

        Livewire::actingAs(staffUser())
            ->test(Form::class)
            ->set('form.name.uz', 'Juda chuqur')
            ->set('form.parent_id', (string) $grandchild->id)
            ->call('save')
            ->assertHasErrors('form.parent_id');

        // Moving a two-level subtree below a second-level category would also make four levels.
        $other = Category::factory()->child(Category::factory()->create())->create();

        expect(fn () => app(SaveCategory::class)->handle($child, categoryData(['parent_id' => $other->id])))
            ->toThrow(ValidationException::class, __('admin.categories.errors.too_deep', ['max' => 3]));
        expect(Category::query()->count())->toBe(5);
    });

    it('blocks deleting from the form while subcategories exist', function () {
        [$root] = categoryChain();

        Livewire::actingAs(staffUser())
            ->test(Form::class, ['category' => $root])
            ->call('delete')
            ->assertDispatched('toast', type: 'error')
            ->assertNoRedirect();
    });

    it('shows inherited attributes read-only and saves own attributes in order', function () {
        [$root] = categoryChain();
        $inherited = Attribute::factory()->select()->create(['code' => 'interface']);
        $root->attributes()->attach($inherited, ['sort' => 1, 'is_required' => true, 'is_filterable' => true]);

        $range = Attribute::factory()->number('m')->create(['code' => 'read_range']);
        $notes = Attribute::factory()->create(['code' => 'notes']);

        $component = Livewire::actingAs(staffUser())
            ->test(Form::class)
            ->set('form.name.uz', 'Simsiz skanerlar')
            ->set('form.parent_id', (string) $root->id)
            ->assertSee('data-inherited="interface"', false)
            ->assertDontSee('pick-'.$inherited->id)
            ->call('attachAttribute', $notes->id)
            ->call('attachAttribute', $range->id)
            ->call('attachAttribute', $inherited->id)
            ->call('sortAttribute', $range->id, 0)
            ->set('form.specs.0.is_required', true)
            ->set('form.specs.1.is_filterable', true)
            ->call('save')
            ->assertHasNoErrors();

        $category = Category::query()->where('parent_id', $root->id)->latest('id')->first();
        $own = $category->attributes()->get();

        expect($own->pluck('code')->all())->toBe(['read_range', 'notes'])
            ->and($own[0]->pivot->is_required)->toBeTrue()
            ->and($own[0]->pivot->sort)->toBe(1)
            ->and($own[1]->pivot->is_filterable)->toBeFalse(); // text attributes are never filters
    });

    it('uploads, relabels and removes the image', function () {
        Storage::fake('public');
        Storage::fake('local');
        $category = Category::factory()->create();

        $component = Livewire::actingAs(staffUser())
            ->test(Form::class, ['category' => $category])
            ->set('form.image', UploadedFile::fake()->image('scanner.jpg', 1600, 1200))
            ->set('form.image_alt.uz', 'Skaner')
            ->set('form.image_alt.ru', 'Сканер')
            ->call('save')
            ->assertHasNoErrors();

        $media = $category->fresh()->getFirstMedia('image');

        expect($media)->not->toBeNull()
            ->and($media->getCustomProperty('alt'))->toBe(['uz' => 'Skaner', 'ru' => 'Сканер', 'en' => ''])
            ->and($media->hasGeneratedConversion('thumb'))->toBeTrue()
            ->and($media->hasGeneratedConversion('large'))->toBeTrue();
        Storage::disk('public')->assertExists($media->getPathRelativeToRoot('thumb'));
        expect($media->getPathRelativeToRoot('thumb'))->toEndWith('.webp');

        $component->set('form.image_alt.en', 'Scanner')->call('save')->assertHasNoErrors();
        expect($media->fresh()->getCustomProperty('alt')['en'])->toBe('Scanner');

        $component->call('removeImage')->call('save');
        expect($category->fresh()->getFirstMedia('image'))->toBeNull();
    });

    it('rejects files that are not images as soon as they are uploaded', function () {
        Storage::fake('public');
        Storage::fake('local');

        Livewire::actingAs(staffUser())
            ->test(Form::class)
            ->set('form.name.uz', 'Rasm')
            ->set('form.image', UploadedFile::fake()->create('evil.svg', 10, 'image/svg+xml'))
            ->assertHasErrors('form.image')
            ->assertSet('form.image', null);
    });
});

describe('authorization', function () {
    it('lets every staff role open the screens read-only', function (Role $role) {
        $category = Category::factory()->create();
        $user = staffUser($role);

        $this->actingAs($user)->get(route('admin.categories.index'))->assertOk();
        $this->actingAs($user)->get(route('admin.categories.edit', $category))->assertOk();
    })->with([...catalogWriterRoles(), ...catalogViewOnlyRoles()]);

    it('forbids writes for view-only roles', function (Role $role) {
        $category = Category::factory()->create();
        $other = Category::factory()->create();
        $user = staffUser($role);

        $this->actingAs($user)->get(route('admin.categories.create'))->assertForbidden();

        Livewire::actingAs($user)->test(Form::class, ['category' => $category])
            ->set('form.name.uz', 'Yangi nom')
            ->call('save')
            ->assertForbidden();

        Livewire::actingAs($user)->test(Index::class)->call('reorder', $other->id, 0)->assertForbidden();
        Livewire::actingAs($user)->test(Index::class)->call('delete', $category->id)->assertForbidden();

        expect($category->fresh()->getTranslation('name', 'uz'))->not->toBe('Yangi nom');
    })->with(catalogViewOnlyRoles());

    it('lets writer roles create categories', function (Role $role) {
        $this->actingAs(staffUser($role))->get(route('admin.categories.create'))->assertOk();
    })->with(catalogWriterRoles());
});

it('renders the seeded catalog tree and a subcategory with inherited attributes', function () {
    $this->seed(CatalogSeeder::class);
    $user = staffUser();

    $this->actingAs($user)->get(route('admin.categories.index'))
        ->assertOk()
        ->assertSee('data-category-id="'.Category::query()->where('slug', 'handheld-scanners')->value('id').'"', false);

    $this->actingAs($user)->get(route('admin.categories.edit', Category::query()->where('slug', 'handheld-scanners')->firstOrFail()))
        ->assertOk()
        ->assertSee('data-inherited="scan_engine"', false)
        ->assertSee('data-inherited="interface"', false);

    $this->actingAs($user)->get(route('admin.attributes.index'))->assertOk();
    $this->actingAs($user)->get(route('admin.brands.index'))->assertOk()->assertSee('Honeywell');
});
