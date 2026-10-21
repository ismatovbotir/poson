<?php

namespace App\Livewire\Admin;

use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Admin home: welcome with the user's role, plus role-aware stat cards
 * (a card shows only when the user may view the resource).
 */
#[Layout('layouts::admin')]
class Dashboard extends Component
{
    /**
     * Card key → [model class, icon]. Product cards (active, drafts, specs
     * incomplete) follow when the user may view products.
     *
     * @var array<string, array{0: class-string<Model>, 1: string}>
     */
    public const CARDS = [
        'categories' => [Category::class, 'folder'],
        'attributes' => [Attribute::class, 'sliders'],
        'brands' => [Brand::class, 'tag'],
    ];

    /**
     * The dashboard is open to every staff role (the "admin" middleware checks that).
     */
    public function mount(): void
    {
        abort_unless(auth()->user() instanceof User && auth()->user()->isStaff(), 403);
    }

    /**
     * @return list<array{key: string, label: string, icon: string, count: int, href?: string}>
     */
    #[Computed]
    public function cards(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $cards = [];

        foreach (self::CARDS as $key => [$model, $icon]) {
            if (! $user->can('viewAny', $model)) {
                continue;
            }

            $cards[] = [
                'key' => $key,
                'label' => __("admin.dashboard.cards.{$key}"),
                'icon' => $icon,
                'count' => $model::query()->count(),
            ];
        }

        if ($user->can('viewAny', Product::class)) {
            $products = [
                'products_active' => ['box', Product::query()->where('is_active', true)->count(), ['tab' => 'active']],
                'products_drafts' => ['edit', Product::query()->where('is_active', false)->count(), ['tab' => 'drafts']],
                'products_incomplete' => ['alert', Product::query()->where('has_missing_specs', true)->count(), ['tab' => 'incomplete']],
            ];

            foreach ($products as $key => [$icon, $count, $query]) {
                $cards[] = [
                    'key' => $key,
                    'label' => __("admin.dashboard.cards.{$key}"),
                    'icon' => $icon,
                    'count' => $count,
                    'href' => route('admin.products.index', $query),
                ];
            }
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
