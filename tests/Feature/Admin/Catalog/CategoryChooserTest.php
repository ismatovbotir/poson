<?php

use App\Enums\Role;
use App\Livewire\Admin\Products\CategoryChooser;
use App\Models\Category;
use App\Support\Catalog\CategoryTree;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

function chooserTree(): array
{
    $printers = Category::factory()->create(['name' => ['uz' => 'Printerlar'], 'icon' => 'print']);
    $label = Category::factory()->child($printers)->create(['name' => ['uz' => 'Yorliq printerlari']]);
    $deep = Category::factory()->child($label)->create(['name' => ['uz' => 'Termo']]);
    $scanners = Category::factory()->create(['name' => ['uz' => 'Skanerlar'], 'is_active' => false]);
    CategoryTree::flush();

    return compact('printers', 'label', 'deep', 'scanners');
}

function openChooser(?Role $role = null)
{
    return Livewire::actingAs(staffUser($role ?? Role::ContentManager))->test(CategoryChooser::class)->dispatch('open-category-chooser');
}

it('authorizes mount and every method (CP-2)', function () {
    chooserTree();
    $content = staffUser(Role::ContentManager);
    $sales = staffUser(Role::SalesManager);

    Livewire::actingAs($sales)->test(CategoryChooser::class)->assertForbidden();

    foreach ([fn ($c) => $c->call('open'), fn ($c) => $c->call('choose', 1)] as $act) {
        $component = Livewire::actingAs($content)->test(CategoryChooser::class);
        $this->actingAs($sales);
        $act($component)->assertForbidden();
    }
});

it('keeps the open flag locked (L-1)', function (Role $role) {
    expect(fn () => Livewire::actingAs(staffUser($role))->test(CategoryChooser::class)->set('chooserOpen', true))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with([...catalogWriterRoles(), 'service engineer' => [Role::ServiceEngineer]]);

it('lists only the top-level categories and only after opening', function () {
    $tree = chooserTree();
    $tile = fn ($key) => 'chooser-root-'.$tree[$key]->id;

    Livewire::actingAs(staffUser(Role::ContentManager))->test(CategoryChooser::class)
        ->assertDontSeeHtml('chooser-root-')
        ->dispatch('open-category-chooser')
        ->assertDispatched('category-chooser-opened')
        ->assertSeeHtml($tile('printers'))->assertSeeHtml($tile('scanners'))
        ->assertDontSeeHtml($tile('label'))->assertDontSeeHtml($tile('deep'))
        ->assertSee('Printerlar')->assertSee(__('admin.categories.inactive'));
});

it('opens the create form for a root id, as int or digit string', function (mixed $asString) {
    $tree = chooserTree();
    $id = $tree['printers']->id;

    openChooser()->call('choose', $asString ? (string) $id : $id)
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.products.create', ['category' => $id]));
})->with(['int' => false, 'string' => true]);

it('rejects tampered or non-root ids without a redirect or exception', function (string $key) {
    $tree = chooserTree();
    $bad = match ($key) {
        'child' => $tree['label']->id,
        'grandchild' => $tree['deep']->id,
        'missing' => 999999,
        'zero' => 0,
        'negative' => -1,
        'digits and letters' => '1abc',
        'decimal' => '1.0',
        'array' => [1],
        'null' => null,
        'too long' => '99999999999999',
    };

    openChooser()->call('choose', $bad)->assertHasErrors('category')->assertNoRedirect();
})->with(['child', 'grandchild', 'missing', 'zero', 'negative', 'digits and letters', 'decimal', 'array', 'null', 'too long']);

it('removes the old search and selection state', function (string $property) {
    expect(fn () => openChooser()->set($property, 'x'))->toThrow(PublicPropertyNotFoundException::class);
})->with(['chosenCategory', 'chooserSearch']);

it('escapes category names (CP-5)', function () {
    Category::factory()->create(['name' => ['uz' => '<img src=x onerror=alert(1)>']]);
    CategoryTree::flush();

    openChooser()
        ->assertDontSeeHtml('<img src=x onerror=alert(1)>')
        ->assertSeeHtml('&lt;img src=x onerror=alert(1)&gt;');
});

it('falls back to the default icon for an unknown icon name', function () {
    $root = Category::factory()->create(['icon' => '"><script>']);
    CategoryTree::flush();

    openChooser()
        ->assertSeeHtml('href="#i-grid"')
        ->assertDontSeeHtml('<script>')
        ->assertDontSeeHtml('"><script>');
});

it('lets a service engineer choose a root category', function () {
    $tree = chooserTree();

    openChooser(Role::ServiceEngineer)->call('choose', $tree['printers']->id)
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.products.create', ['category' => $tree['printers']->id]));
});
