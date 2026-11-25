{{--
    The filter sheet of a Mini App category (CategoryController::filters): a GET form back to the
    listing with sort, brand, stock, Retail price and the attribute filters (All / Any only where the
    attribute allows the switch, colour swatches as SVG fill only, never style=).
--}}
@extends('layouts.tma')

@section('content')
    <section class="tma-card" data-filters>
        <form method="GET" action="{{ route('tma.category', ['category' => $category->slug]) }}" class="grid gap-4">
            <label class="grid gap-1 text-sm">
                <span class="font-medium">{{ __('tma.filters.sort') }}</span>
                <select name="sort" class="tma-input">
                    @foreach ($sorts as $option)
                        <option value="{{ $option }}" @selected($sort === $option)>{{ __('site.catalog.sort.'.$option) }}</option>
                    @endforeach
                </select>
            </label>

            @if ($facets['brands'] !== [])
                <fieldset class="grid gap-1.5 text-sm">
                    <legend class="mb-1 font-medium">{{ __('tma.filters.brand') }}</legend>
                    @foreach ($facets['brands'] as $brand)
                        <label class="flex items-center gap-2 @if ($brand['disabled'] ?? false) opacity-50 @endif">
                            <input type="checkbox" name="brand[]" value="{{ $brand['slug'] }}" @checked($brand['checked']) @disabled($brand['disabled'] ?? false) class="size-4">
                            <span>{{ $brand['name'] }}</span>
                            <span class="text-tg-hint">{{ $brand['count'] }}</span>
                        </label>
                    @endforeach
                </fieldset>
            @endif

            @if ($facets['stock'] !== [])
                <fieldset class="grid gap-1.5 text-sm">
                    <legend class="mb-1 font-medium">{{ __('tma.filters.stock') }}</legend>
                    @foreach ($facets['stock'] as $stock)
                        <label class="flex items-center gap-2 @if ($stock['disabled'] ?? false) opacity-50 @endif">
                            <input type="checkbox" name="stock[]" value="{{ $stock['value'] }}" @checked($stock['checked']) @disabled($stock['disabled'] ?? false) class="size-4">
                            <span>{{ $stock['label'] }}</span>
                            <span class="text-tg-hint">{{ $stock['count'] }}</span>
                        </label>
                    @endforeach
                </fieldset>
            @endif

            @if ($facets['price']['max'] !== null)
                <fieldset class="grid gap-1.5 text-sm">
                    <legend class="mb-1 font-medium">{{ __('tma.filters.price') }}</legend>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="number" inputmode="numeric" min="0" name="price_min" value="{{ $priceMin }}" placeholder="{{ __('tma.filters.from', ['value' => $facets['price']['min']]) }}" class="tma-input" aria-label="{{ __('tma.filters.price_min') }}">
                        <input type="number" inputmode="numeric" min="0" name="price_max" value="{{ $priceMax }}" placeholder="{{ __('tma.filters.to', ['value' => $facets['price']['max']]) }}" class="tma-input" aria-label="{{ __('tma.filters.price_max') }}">
                    </div>
                </fieldset>
            @endif

            @foreach ($facets['attributes'] as $facet)
                <fieldset class="grid gap-1.5 text-sm" data-attribute-filter="{{ $facet['code'] }}">
                    <legend class="mb-1 font-medium">{{ $facet['name'] }}@if ($facet['unit']) ({{ $facet['unit'] }})@endif</legend>

                    @if ($facet['type'] === \App\Enums\AttributeType::Number)
                        <div class="grid grid-cols-2 gap-2">
                            <input type="number" step="any" name="f[{{ $facet['code'] }}][min]" value="{{ $facet['from'] }}" placeholder="{{ __('tma.filters.from', ['value' => $facet['min']]) }}" class="tma-input" aria-label="{{ $facet['name'] }} {{ __('tma.filters.min') }}">
                            <input type="number" step="any" name="f[{{ $facet['code'] }}][max]" value="{{ $facet['to'] }}" placeholder="{{ __('tma.filters.to', ['value' => $facet['max']]) }}" class="tma-input" aria-label="{{ $facet['name'] }} {{ __('tma.filters.max') }}">
                        </div>
                    @elseif ($facet['type'] === \App\Enums\AttributeType::Boolean)
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="f[{{ $facet['code'] }}][]" value="1" @checked($facet['checked']) class="size-4">
                            <span>{{ __('tma.filters.yes') }}</span>
                            <span class="text-tg-hint">{{ $facet['count'] }}</span>
                        </label>
                    @else
                        @foreach ($facet['options'] as $option)
                            <label class="flex items-center gap-2 @if ($option['disabled']) opacity-50 @endif">
                                <input type="checkbox" name="f[{{ $facet['code'] }}][]" value="{{ $option['id'] }}" @checked($option['checked']) @disabled($option['disabled']) class="size-4">
                                @if (! empty($option['hex']))
                                    <svg viewBox="0 0 16 16" class="size-4 shrink-0" aria-hidden="true" focusable="false" data-swatch><circle cx="8" cy="8" r="7" fill="{{ $option['hex'] }}" class="stroke-ink-soft" stroke-width="1"></circle></svg>
                                @endif
                                <span>{{ $option['label'] }}</span>
                                <span class="text-tg-hint">{{ $option['count'] }}</span>
                            </label>
                        @endforeach

                        @if ($facet['switchable'])
                            <div class="flex gap-4 text-xs text-tg-hint" data-match-switch>
                                <label class="flex items-center gap-1.5"><input type="radio" name="m[{{ $facet['code'] }}]" value="all" @checked($facet['mode'] === 'all')> {{ __('tma.filters.match_all') }}</label>
                                <label class="flex items-center gap-1.5"><input type="radio" name="m[{{ $facet['code'] }}]" value="any" @checked($facet['mode'] === 'any')> {{ __('tma.filters.match_any') }}</label>
                            </div>
                        @endif
                    @endif
                </fieldset>
            @endforeach

            <div class="grid grid-cols-2 gap-2">
                <a href="{{ route('tma.category', ['category' => $category->slug]) }}" class="tma-btn-outline">{{ __('tma.filters.reset') }}</a>
                <button type="submit" class="tma-btn">{{ __('tma.filters.apply') }}</button>
            </div>
        </form>
    </section>
@endsection
