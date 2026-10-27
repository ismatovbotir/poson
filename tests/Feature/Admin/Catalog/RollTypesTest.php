<?php

use App\Actions\Catalog\DeleteRollType;
use App\Actions\Catalog\SaveRollType;
use App\Enums\Role;
use App\Livewire\Admin\RollTypes\Form;
use App\Livewire\Admin\RollTypes\Index;
use App\Models\Product;
use App\Models\RollType;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

it('lists roll types with their product count', function () {
    $roll = RollType::factory()->size(1000, '40.0')->create();
    Product::factory()->perLabel()->withRollTypes([$roll])->create();
    RollType::factory()->size(250, '12.7')->inactive()->create();

    $this->actingAs(staffUser())
        ->get(route('admin.roll-types.index'))
        ->assertOk()
        ->assertSeeLivewire(Index::class)
        ->assertSee("1\u{00A0}000 yorliq/rulon, gilza 40 mm")
        ->assertSee('250 yorliq/rulon, gilza 12,7 mm')
        ->assertSee('data-roll-type="'.$roll->id.'"', false);
});

it('creates and updates a roll type from the form', function () {
    Livewire::actingAs(staffUser(Role::ContentManager))
        ->test(Form::class)
        ->set('form.labels_per_roll', '750')
        ->set('form.core_diameter_mm', '12,7')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $roll = RollType::query()->sole();

    expect($roll->labels_per_roll)->toBe(750)
        ->and($roll->core_diameter_mm)->toBe('12.7')
        ->and($roll->is_active)->toBeTrue();

    Livewire::actingAs(staffUser())
        ->test(Form::class, ['rollType' => $roll])
        ->assertSet('form.core_diameter_mm', '12.7')
        ->set('form.labels_per_roll', '800')
        ->set('form.is_active', false)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    expect($roll->fresh())->labels_per_roll->toBe(800)->is_active->toBeFalse();
});

it('rejects duplicate pairs and invalid values', function () {
    RollType::factory()->size(500, '40.0')->create();

    Livewire::actingAs(staffUser())
        ->test(Form::class)
        ->set('form.labels_per_roll', '500')
        ->set('form.core_diameter_mm', '40')
        ->call('save')
        ->assertHasErrors(['form.labels_per_roll']);

    Livewire::actingAs(staffUser())
        ->test(Form::class)
        ->set('form.labels_per_roll', '0')
        ->set('form.core_diameter_mm', '40.25')
        ->call('save')
        ->assertHasErrors(['form.labels_per_roll', 'form.core_diameter_mm']);

    expect(SaveRollType::normalizeCore('76'))->toBe('76.0')
        ->and(SaveRollType::normalizeCore(' 12,7 '))->toBe('12.7')
        ->and(SaveRollType::normalizeCore('0'))->toBeNull()
        ->and(SaveRollType::normalizeCore('abc'))->toBeNull()
        ->and(RollType::query()->count())->toBe(1);
});

it('blocks deleting a roll type attached to a product, trashed ones too', function () {
    $roll = RollType::factory()->create();
    $product = Product::factory()->perLabel()->withRollTypes([$roll])->create();
    $product->delete();
    $free = RollType::factory()->create();

    Livewire::actingAs(staffUser())
        ->test(Index::class)
        ->call('delete', $roll->id)
        ->assertDispatched('toast', type: 'error')
        ->call('delete', $free->id)
        ->assertDispatched('toast');

    expect(RollType::query()->pluck('id')->all())->toBe([$roll->id]);
});

it('lets catalog writers manage roll types and the other roles only view them', function (Role $role, bool $canWrite) {
    $user = staffUser($role);
    $roll = RollType::factory()->create();

    $this->actingAs($user)->get(route('admin.roll-types.index'))->assertOk();
    $this->actingAs($user)->get(route('admin.roll-types.edit', $roll))->assertOk();
    $this->actingAs($user)->get(route('admin.roll-types.create'))->assertStatus($canWrite ? 200 : 403);

    expect($user->can('update', $roll))->toBe($canWrite)
        ->and($user->can('delete', $roll))->toBe($canWrite);

    if (! $canWrite) {
        Livewire::actingAs($user)->test(Form::class, ['rollType' => $roll])->set('form.labels_per_roll', '42')->call('save')->assertForbidden();
        Livewire::actingAs($user)->test(Index::class)->call('delete', $roll->id)->assertForbidden();
    }
})->with([
    'admin' => [Role::Admin, true],
    'content manager' => [Role::ContentManager, true],
    'sales manager' => [Role::SalesManager, false],
    'service engineer' => [Role::ServiceEngineer, false],
]);

it('keeps users without a staff role out', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.roll-types.index'))->assertRedirect();
});

it('seeds the label roll types idempotently', function () {
    $this->seed(CatalogSeeder::class);
    $this->seed(CatalogSeeder::class);

    expect(RollType::query()->count())->toBe(10)
        ->and(RollType::query()->ordered()->get()->map(fn (RollType $roll): string => $roll->labels_per_roll.'@'.$roll->core_diameter_mm)->all())->toBe([
            '250@12.7', '500@12.7', '500@25.0', '1000@25.0', '500@40.0', '1000@40.0', '2000@40.0', '2000@76.0', '3000@76.0', '5000@76.0',
        ]);
});

it('warns, without blocking, when hiding a roll type leaves active label products without one', function () {
    $only = RollType::factory()->create();
    $shared = RollType::factory()->create();
    $other = RollType::factory()->create();
    Product::factory()->perLabel()->withRollTypes([$only])->create();
    Product::factory()->perLabel()->withRollTypes([$shared, $other])->create();
    Product::factory()->perLabel()->inactive()->withRollTypes([$only])->create();

    expect(SaveRollType::productsLeftWithoutRoll($only))->toBe(1)
        ->and(SaveRollType::productsLeftWithoutRoll($shared))->toBe(0);

    Livewire::actingAs(staffUser())
        ->test(Form::class, ['rollType' => $only])
        ->set('form.is_active', false)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', type: 'error');

    expect($only->fresh()->is_active)->toBeFalse();

    Livewire::actingAs(staffUser())
        ->test(Form::class, ['rollType' => $shared])
        ->set('form.is_active', false)
        ->call('save')
        ->assertNotDispatched('toast', type: 'error');
});

it('reports a roll type attached between the check and the delete as attached', function () {
    $roll = RollType::factory()->create();
    $product = Product::factory()->perLabel()->create();

    // Simulates a product form attaching it concurrently: the foreign key restricts the delete.
    RollType::deleting(function (RollType $deleting) use ($product): void {
        DB::table('product_roll_type')->insert(['product_id' => $product->id, 'roll_type_id' => $deleting->id, 'sort' => 1]);
    });

    expect(fn () => app(DeleteRollType::class)->handle($roll))
        ->toThrow(ValidationException::class, __('admin.roll_types.errors.attached'))
        ->and(RollType::query()->whereKey($roll->id)->exists())->toBeTrue();
});
