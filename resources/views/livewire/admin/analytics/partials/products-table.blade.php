{{-- Top products. $rows: list<array{product: Product, hits, visitors}>; $hitsLabel. --}}
@if ($rows === [])
    <p class="px-4 py-6 text-sm text-slate-500 sm:px-6">{{ __('analytics.common.no_data') }}</p>
@else
    <ul class="divide-y divide-slate-100">
        @foreach ($rows as $row)
            <li wire:key="product-{{ $row['product']->id }}" class="flex items-center gap-3 px-4 py-2.5 text-sm sm:px-6" data-product="{{ $row['product']->id }}">
                <div class="min-w-0 flex-1">
                    @if ($row['product']->trashed())
                        <p class="truncate font-medium text-slate-500" data-trashed>{{ $row['product']->name }}</p>
                    @else
                        <a href="{{ route('admin.products.edit', $row['product']) }}" wire:navigate class="block truncate font-medium hover:text-brand-700">{{ $row['product']->name }}</a>
                    @endif
                    <p class="truncate font-mono text-xs text-slate-500">{{ $row['product']->sku }}</p>
                </div>
                <span class="shrink-0 text-right tabular-nums"><b class="font-semibold">{{ \App\Support\NumberFormat::format($row['hits']) }}</b> <span class="text-xs text-slate-500">{{ $hitsLabel }}</span></span>
            </li>
        @endforeach
    </ul>
@endif
