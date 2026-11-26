{{-- Mini App search (SearchController): results with their cart action; hints offer categories and a manager. --}}
@extends('layouts.tma')

@section('content')
    @include('tma.partials.search-form')

    @if ($cards === [])
        <section class="grid gap-3">
            <p class="tma-card text-tg-hint" @if ($products !== null) data-no-results @endif>{{ $products === null ? __('tma.search.hint') : __('tma.search.empty') }}</p>
            @include('tma.partials.category-chips', ['categories' => $categories])
            @if ($products !== null && $botLink !== null)
                <a href="{{ $botLink }}" data-tg-link="{{ $botLink }}" class="tma-btn-outline" data-contact-manager>{{ __('tma.contact_manager') }}</a>
            @endif
        </section>
    @else
        <div class="grid gap-2">
            @foreach ($cards as $item)
                @include('tma.partials.product-card', ['item' => $item, 'from' => \App\Support\Telegram\MiniApp\TmaReturn::PRODUCT])
            @endforeach
        </div>
        @include('tma.partials.pager', ['paginator' => $products])
    @endif
@endsection
