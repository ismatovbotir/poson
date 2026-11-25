{{-- Mini App home (HomeController): full logo, search, categories, My orders. --}}
@extends('layouts.tma', ['home' => true, 'title' => __('tma.home.title')])

@section('content')
    @include('tma.partials.search-form')

    <section class="grid gap-2" aria-labelledby="tma-categories">
        <h2 id="tma-categories" class="px-1 text-sm font-semibold text-tg-hint">{{ __('tma.home.categories') }}</h2>
        <div class="grid grid-cols-2 gap-2">
            @forelse ($categories as $category)
                <a href="{{ $category['url'] }}" class="tma-card min-h-16 font-medium" data-category>{{ $category['label'] }}</a>
            @empty
                <p class="tma-card col-span-2 text-tg-hint">{{ __('tma.home.empty') }}</p>
            @endforelse
        </div>
    </section>

    <section class="grid gap-2">
        <a href="{{ route('tma.orders') }}" class="tma-card flex items-center justify-between font-medium" data-my-orders>
            {{ __('tma.home.my_orders') }} <span aria-hidden="true">›</span>
        </a>
        @if ($botLink !== null)
            <a href="{{ $botLink }}" data-tg-link="{{ $botLink }}" class="tma-card flex items-center justify-between font-medium" data-contact-manager>
                {{ __('tma.contact_manager') }} <span aria-hidden="true">›</span>
            </a>
        @endif
    </section>

    <p class="px-1 text-xs text-tg-hint">{{ __('tma.home.service_note') }}</p>
@endsection
