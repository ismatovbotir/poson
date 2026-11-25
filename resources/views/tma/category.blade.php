{{--
    Mini App category listing (CategoryController): subcategories, the link to the filter sheet
    (tma/filters, its own screen), products, pager.
--}}
@extends('layouts.tma')

@section('content')
    @if ($subcategories !== [])
        <nav class="flex gap-2 overflow-x-auto pb-1" aria-label="{{ __('tma.category.subcategories') }}">
            @foreach ($subcategories as $subcategory)
                <a href="{{ $subcategory['url'] }}" class="shrink-0 rounded-full bg-tg-section px-3 py-1.5 text-sm font-medium" data-subcategory>{{ $subcategory['label'] }}</a>
            @endforeach
        </nav>
    @endif

    <a href="{{ $filtersUrl }}" class="tma-card flex items-center justify-between font-semibold" data-filters-link>
        <span>{{ __('tma.filters.title') }}@if ($activeCount > 0) ({{ $activeCount }})@endif</span>
        <span aria-hidden="true">›</span>
    </a>

    @if ($cards === [])
        <p class="tma-card text-tg-hint" data-empty>{{ __('tma.category.empty') }}</p>
    @else
        <div class="grid gap-2">
            @foreach ($cards as $card)
                @include('tma.partials.product-card', ['card' => $card])
            @endforeach
        </div>
        @include('tma.partials.pager', ['paginator' => $products])
    @endif
@endsection
