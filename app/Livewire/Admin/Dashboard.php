<?php

namespace App\Livewire\Admin;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Admin home: welcome with the user's role, plus role-aware stat cards.
 * A card shows only when the user may view the resource and its model and
 * table exist (catalog tables arrive in plan 03 3b/3c).
 */
#[Layout('layouts::admin')]
class Dashboard extends Component
{
    /**
     * Card key → [model class, table, permission, icon].
     *
     * @var array<string, array{0: class-string, 1: string, 2: string, 3: string}>
     */
    public const CARDS = [
        'categories' => ['App\\Models\\Category', 'categories', 'categories.viewAny', 'folder'],
        'attributes' => ['App\\Models\\Attribute', 'attributes', 'attributes.viewAny', 'sliders'],
        'brands' => ['App\\Models\\Brand', 'brands', 'brands.viewAny', 'tag'],
        'products' => ['App\\Models\\Product', 'products', 'products.viewAny', 'box'],
    ];

    /**
     * The dashboard is open to every staff role (the "admin" middleware checks that).
     */
    public function mount(): void
    {
        abort_unless(auth()->user() instanceof User && auth()->user()->isStaff(), 403);
    }

    /**
     * @return list<array{key: string, label: string, icon: string, count: int}>
     */
    #[Computed]
    public function cards(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $cards = [];

        foreach (self::CARDS as $key => [$model, $table, $permission, $icon]) {
            if (! class_exists($model) || ! Schema::hasTable($table) || ! $user->can($permission)) {
                continue;
            }

            $cards[] = [
                'key' => $key,
                'label' => __("admin.dashboard.cards.{$key}"),
                'icon' => $icon,
                'count' => $model::query()->count(),
            ];
        }

        return $cards;
    }

    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();

        return view('livewire.admin.dashboard', [
            'user' => $user,
            'role' => $user->staffRole(),
        ])->title(__('admin.dashboard.title'));
    }
}
