{{-- Retail price (per label for label products), or "on request" when there is none. --}}
@if ($product->price !== null)
    {{-- Plan 08: a label product's price is per one label. --}}
    <span class="font-semibold">{{ $product->isPricedPerLabel() ? __('catalog.roll.per_label', ['price' => \App\Support\Money::format($product->price)]) : \App\Support\Money::format($product->price) }}</span>
@else
    <span class="text-sm font-medium text-slate-500">{{ __('admin.products.price_on_request') }}</span>
@endif
