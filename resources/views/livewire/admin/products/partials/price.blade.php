{{-- Retail price, or "on request" when there is none. --}}
@if ($product->price !== null)
    <span class="font-semibold">{{ \App\Support\Money::format($product->price) }}</span>
@else
    <span class="text-sm font-medium text-slate-500">{{ __('admin.products.price_on_request') }}</span>
@endif
