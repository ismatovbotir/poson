{{-- Mini App search (SearchController). --}}
@extends('layouts.tma')

@section('content')
    @include('tma.partials.search-form')

    @if ($products === null)
        <p class="tma-card text-tg-hint">{{ __('tma.search.hint') }}</p>
    @elseif ($cards === [])
        <p class="tma-card text-tg-hint" data-no-results>{{ __('tma.search.empty') }}</p>
    @else
        <div class="grid gap-2">
            @foreach ($cards as $card)
                @include('tma.partials.product-card', ['card' => $card])
            @endforeach
        </div>
        @include('tma.partials.pager', ['paginator' => $products])
    @endif
@endsection
