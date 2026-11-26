{{--
    The filter sheet of a Mini App category (CategoryController::filters): a GET form back to the
    listing with sort, brand, stock, Retail price and the attribute filters (All / Any only where the
    attribute allows the switch, colour swatches as SVG fill only, never style=).
    Plan 45 §2: one <details> per facet (open while it has a value), 48 px rows, Apply in a sticky bar
    (MainButton), Reset as a text link in the header.
--}}
@extends('layouts.tma', ['headerLink' => ['url' => route('tma.category', ['category' => $category->slug]), 'label' => __('tma.filters.reset')]])

@php
    $row = 'tma-row border-t border-tg-separator text-sm first-of-type:border-0';
    $box = 'size-5 shrink-0 accent-tg-button';
    $summary = 'tma-row cursor-pointer justify-between font-medium';
@endphp

@section('content')
    <form method="GET" action="{{ route('tma.category', ['category' => $category->slug]) }}" class="grid gap-2" data-filters>
        <details class="tma-group" id="tma-sort" open>
            <summary class="{{ $summary }}">{{ __('tma.filters.sort') }} <x-tma.icon name="sort" class="text-tg-hint" /></summary>
            <fieldset class="grid pb-1"><legend class="sr-only">{{ __('tma.filters.sort') }}</legend>
                @foreach ($sorts as $option)
                    <label class="{{ $row }}"><input type="radio" name="sort" value="{{ $option }}" @checked($sort === $option) class="{{ $box }}"> <span class="flex-1">{{ __('site.catalog.sort.'.$option) }}</span></label>
                @endforeach
            </fieldset>
        </details>

        @if ($facets['brands'] !== [])
            <details class="tma-group" @if (collect($facets['brands'])->contains('checked', true)) open @endif>
                <summary class="{{ $summary }}">{{ __('tma.filters.brand') }} <x-tma.icon name="chevron" class="text-tg-hint" /></summary>
                <fieldset class="grid pb-1"><legend class="sr-only">{{ __('tma.filters.brand') }}</legend>
                    @foreach ($facets['brands'] as $brand)
                        <label class="{{ $row }} @if ($brand['disabled'] ?? false) opacity-50 @endif">
                            <input type="checkbox" name="brand[]" value="{{ $brand['slug'] }}" @checked($brand['checked']) @disabled($brand['disabled'] ?? false) class="{{ $box }}">
                            <span class="flex-1">{{ $brand['name'] }}</span>
                            <span class="text-tg-hint">{{ $brand['count'] }}</span>
                        </label>
                    @endforeach
                </fieldset>
            </details>
        @endif

        @if ($facets['stock'] !== [])
            <details class="tma-group" @if (collect($facets['stock'])->contains('checked', true)) open @endif>
                <summary class="{{ $summary }}">{{ __('tma.filters.stock') }} <x-tma.icon name="chevron" class="text-tg-hint" /></summary>
                <fieldset class="grid pb-1"><legend class="sr-only">{{ __('tma.filters.stock') }}</legend>
                    @foreach ($facets['stock'] as $stock)
                        <label class="{{ $row }} @if ($stock['disabled'] ?? false) opacity-50 @endif">
                            <input type="checkbox" name="stock[]" value="{{ $stock['value'] }}" @checked($stock['checked']) @disabled($stock['disabled'] ?? false) class="{{ $box }}">
                            <span class="flex-1">{{ $stock['label'] }}</span>
                            <span class="text-tg-hint">{{ $stock['count'] }}</span>
                        </label>
                    @endforeach
                </fieldset>
            </details>
        @endif

        @if ($facets['price']['max'] !== null)
            <details class="tma-group" @if ($priceMin !== null || $priceMax !== null) open @endif>
                <summary class="{{ $summary }}">{{ __('tma.filters.price') }} <x-tma.icon name="chevron" class="text-tg-hint" /></summary>
                <div class="grid grid-cols-2 gap-2 pb-3">
                    <input type="number" inputmode="numeric" min="0" name="price_min" value="{{ $priceMin }}" placeholder="{{ __('tma.filters.from', ['value' => $facets['price']['min']]) }}" class="tma-input" aria-label="{{ __('tma.filters.price_min') }}">
                    <input type="number" inputmode="numeric" min="0" name="price_max" value="{{ $priceMax }}" placeholder="{{ __('tma.filters.to', ['value' => $facets['price']['max']]) }}" class="tma-input" aria-label="{{ __('tma.filters.price_max') }}">
                </div>
            </details>
        @endif

        @foreach ($facets['attributes'] as $facet)
            @php
                $active = match ($facet['type']) {
                    \App\Enums\AttributeType::Number => $facet['from'] !== null || $facet['to'] !== null,
                    \App\Enums\AttributeType::Boolean => (bool) $facet['checked'],
                    default => collect($facet['options'])->contains('checked', true),
                };
            @endphp
            <details class="tma-group" data-attribute-filter="{{ $facet['code'] }}" @if ($active) open @endif>
                <summary class="{{ $summary }}">{{ $facet['name'] }}@if ($facet['unit']) ({{ $facet['unit'] }})@endif <x-tma.icon name="chevron" class="text-tg-hint" /></summary>
                <fieldset class="grid"><legend class="sr-only">{{ $facet['name'] }}</legend>
                @if ($facet['type'] === \App\Enums\AttributeType::Number)
                    <div class="grid grid-cols-2 gap-2 pb-3">
                        <input type="number" step="any" name="f[{{ $facet['code'] }}][min]" value="{{ $facet['from'] }}" placeholder="{{ __('tma.filters.from', ['value' => $facet['min']]) }}" class="tma-input" aria-label="{{ $facet['name'] }} {{ __('tma.filters.min') }}">
                        <input type="number" step="any" name="f[{{ $facet['code'] }}][max]" value="{{ $facet['to'] }}" placeholder="{{ __('tma.filters.to', ['value' => $facet['max']]) }}" class="tma-input" aria-label="{{ $facet['name'] }} {{ __('tma.filters.max') }}">
                    </div>
                @elseif ($facet['type'] === \App\Enums\AttributeType::Boolean)
                    <div class="grid pb-1">
                        <label class="{{ $row }}">
                            <input type="checkbox" name="f[{{ $facet['code'] }}][]" value="1" @checked($facet['checked']) class="{{ $box }}">
                            <span class="flex-1">{{ __('tma.filters.yes') }}</span>
                            <span class="text-tg-hint">{{ $facet['count'] }}</span>
                        </label>
                    </div>
                @else
                    <div class="grid pb-1">
                        @foreach ($facet['options'] as $option)
                            <label class="{{ $row }} @if ($option['disabled']) opacity-50 @endif">
                                <input type="checkbox" name="f[{{ $facet['code'] }}][]" value="{{ $option['id'] }}" @checked($option['checked']) @disabled($option['disabled']) class="{{ $box }}">
                                @if (! empty($option['hex']))
                                    <svg viewBox="0 0 16 16" class="size-4 shrink-0" aria-hidden="true" focusable="false" data-swatch><circle cx="8" cy="8" r="7" fill="{{ $option['hex'] }}" class="stroke-ink-soft" stroke-width="1"></circle></svg>
                                @endif
                                <span class="flex-1">{{ $option['label'] }}</span>
                                <span class="text-tg-hint">{{ $option['count'] }}</span>
                            </label>
                        @endforeach

                        @if ($facet['switchable'])
                            <div class="flex gap-2 border-t border-tg-separator py-1 text-sm text-tg-subtitle" data-match-switch>
                                <label class="tma-row w-auto flex-1 gap-2"><input type="radio" name="m[{{ $facet['code'] }}]" value="all" @checked($facet['mode'] === 'all') class="{{ $box }}"> {{ __('tma.filters.match_all') }}</label>
                                <label class="tma-row w-auto flex-1 gap-2"><input type="radio" name="m[{{ $facet['code'] }}]" value="any" @checked($facet['mode'] === 'any') class="{{ $box }}"> {{ __('tma.filters.match_any') }}</label>
                            </div>
                        @endif
                    </div>
                @endif
                </fieldset>
            </details>
        @endforeach

        <div class="tma-bar">
            <button type="submit" class="tma-btn mx-auto max-w-xl" data-main-button="{{ __('tma.filters.apply') }}">{{ __('tma.filters.apply') }}</button>
        </div>
    </form>
@endsection
