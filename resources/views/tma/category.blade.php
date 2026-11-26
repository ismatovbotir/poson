{{--
    Mini App category listing (CategoryController, plan 45 §2): parent breadcrumbs, a sticky toolbar
    (Filters with the active count, Sort, removable active-filter chips — plain GET links), the
    subcategory chips, cards with their cart action, the pager. The filter sheet is its own screen.
--}}
@extends('layouts.tma', ['titleIcon' => $icon])

@section('content')
    @if ($breadcrumbs !== [] || $subcategories !== [])
    <div class="grid gap-2">
        @if ($breadcrumbs !== [])
            <nav class="flex flex-wrap items-center gap-x-1 text-sm text-tg-hint" aria-label="{{ __('tma.category.breadcrumbs') }}" data-breadcrumbs>
                @foreach ($breadcrumbs as $crumb)
                    <a href="{{ $crumb['url'] }}" class="inline-flex min-h-11 items-center gap-1"><x-tma.icon :name="$crumb['icon']" size="size-4" /> {{ $crumb['label'] }}</a>
                    <x-tma.icon name="chevron" size="size-4" />
                @endforeach
            </nav>
        @endif

        @if ($subcategories !== [])
            <nav class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1" aria-label="{{ __('tma.category.subcategories') }}">
                @foreach ($subcategories as $subcategory)
                    <a href="{{ $subcategory['url'] }}" class="tma-chip" data-subcategory><x-tma.icon :name="$subcategory['icon']" size="size-[18px]" class="text-tg-hint" /> {{ $subcategory['label'] }}</a>
                @endforeach
            </nav>
        @endif
    </div>
    @endif

    <div class="sticky top-14 z-[5] -mx-4 flex gap-2 overflow-x-auto bg-tg-secondary px-4 py-2" data-toolbar>
        <a href="{{ $filtersUrl }}" class="tma-chip" data-filters-link>
            <x-tma.icon name="filter" size="size-[18px]" /> {{ __('tma.category.filters') }}
            @if ($activeCount > 0)
                <span class="tma-badge bg-tg-button text-tg-button-text" data-filter-count>{{ $activeCount }}</span>
            @endif
        </a>
        <a href="{{ $filtersUrl }}#tma-sort" class="tma-chip" data-sort-link><x-tma.icon name="sort" size="size-[18px]" /> {{ $sortLabel }}</a>
        @foreach ($chips as $chip)
            <a href="{{ $chip['url'] }}" class="tma-chip bg-tg-button text-tg-button-text" aria-label="{{ __('tma.category.remove_filter', ['filter' => $chip['label']]) }}" data-filter-chip>{{ $chip['label'] }} <x-tma.icon name="close" size="size-4" /></a>
        @endforeach
    </div>

    @if ($cards === [])
        <section class="tma-group grid justify-items-center gap-3 py-6 text-center" data-empty>
            <x-tma.icon :name="$icon" size="size-8" class="text-tg-hint" />
            <p class="text-tg-hint">{{ __('tma.category.empty') }}</p>
            @if ($activeCount > 0)
                <a href="{{ route('tma.category', ['category' => $category->slug]) }}" class="tma-btn-outline">{{ __('tma.filters.reset') }}</a>
            @elseif ($botLink !== null)
                <a href="{{ $botLink }}" data-tg-link="{{ $botLink }}" class="tma-btn-outline" data-contact-manager>{{ __('tma.contact_manager') }}</a>
            @endif
        </section>
    @else
        <div class="grid gap-2">
            @foreach ($cards as $item)
                @include('tma.partials.product-card', ['item' => $item, 'from' => $from])
            @endforeach
        </div>
        @include('tma.partials.pager', ['paginator' => $products])
    @endif
@endsection
