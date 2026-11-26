{{--
    Mini App home (HomeController, plan 45 §2): full logo, search, the category grid with icons, the
    "Why Poson" service card (service claims only, MA-54), then My orders / Service / Contact a manager.
--}}
@extends('layouts.tma', ['home' => true, 'title' => __('tma.home.title')])

@section('content')
    @include('tma.partials.search-form')

    <section class="grid gap-2" aria-labelledby="tma-categories">
        <h2 id="tma-categories" class="px-1 text-sm font-semibold text-tg-header">{{ __('tma.home.categories') }}</h2>
        <div class="grid grid-cols-2 gap-2">
            @forelse ($categories as $category)
                <a href="{{ $category['url'] }}" class="tma-card grid min-h-28 content-start gap-2" data-category>
                    <span class="grid size-12 place-items-center rounded-xl bg-tg-secondary text-tg-link"><x-tma.icon :name="$category['icon']" size="size-7" /></span>
                    <span class="line-clamp-2 text-sm leading-snug font-medium">{{ $category['label'] }}</span>
                </a>
            @empty
                <p class="tma-card col-span-2 text-tg-hint">{{ __('tma.home.empty') }}</p>
            @endforelse
        </div>
    </section>

    <section class="tma-group grid py-1" aria-labelledby="tma-why" data-why-poson>
        <h2 id="tma-why" class="pt-2 pb-1 text-sm font-semibold text-tg-header">{{ __('tma.home.why_title') }}</h2>
        @foreach (['warranty' => 'shield', 'service' => 'wrench', 'setup' => 'cat-setup-integration'] as $point => $icon)
            <div class="tma-row border-t border-tg-separator first-of-type:border-0">
                <x-tma.icon :name="$icon" size="size-6" class="text-tg-accent" />
                <span class="grid">
                    <span class="font-medium">{{ __('tma.home.why.'.$point.'.title') }}</span>
                    <span class="text-sm text-tg-subtitle">{{ __('tma.home.why.'.$point.'.text') }}</span>
                </span>
            </div>
        @endforeach
    </section>

    <nav class="tma-group grid" aria-label="{{ __('tma.home.more') }}">
        <a href="{{ route('tma.orders') }}" class="tma-row justify-between font-medium" data-my-orders>
            {{ __('tma.home.my_orders') }} <x-tma.icon name="chevron" class="text-tg-hint" />
        </a>
        @if ($botLink !== null)
            <a href="{{ $botLink }}" data-tg-link="{{ $botLink }}" class="tma-row justify-between border-t border-tg-separator font-medium" data-contact-manager>
                {{ __('tma.contact_manager') }} <x-tma.icon name="chevron" class="text-tg-hint" />
            </a>
        @endif
    </nav>
@endsection
