<?php

namespace App\Livewire\Admin\PriceSync;

use App\Models\PriceSyncLog;
use App\Models\Product;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One price API batch (/admin/price-sync/{id}, plan 09): summary, the
 * unknown codes and the per-item table (status, errors, old → new prices
 * of updated items). price-sync.view (Admin only).
 */
#[Layout('layouts::admin')]
class Show extends Component
{
    #[Locked]
    public PriceSyncLog $log;

    public function mount(PriceSyncLog $priceSyncLog): void
    {
        $this->authorize('view', $priceSyncLog);
        $this->log = $priceSyncLog;
    }

    public function render(): View
    {
        $this->authorize('view', $this->log);

        $results = array_values((array) $this->log->results);
        $productIds = array_values(array_unique(array_filter(array_map(fn (array $row): int => (int) ($row['product_id'] ?? 0), $results))));

        return view('livewire.admin.price-sync.show', [
            'results' => $results,
            'products' => Product::withTrashed()->whereIn('id', $productIds)->get(['id', 'sku', 'name', 'deleted_at'])->keyBy('id'),
        ])->title(__('api.sync.show_title', ['id' => $this->log->id]));
    }
}
