{{-- Category chips with icons ($categories: list of label / url / icon), e.g. search hints. --}}
@if ($categories !== [])
    <nav class="flex flex-wrap gap-2" aria-label="{{ __('tma.home.categories') }}" data-category-chips>
        @foreach ($categories as $category)
            <a href="{{ $category['url'] }}" class="tma-chip"><x-tma.icon :name="$category['icon']" size="size-[18px]" class="text-tg-hint" /> {{ $category['label'] }}</a>
        @endforeach
    </nav>
@endif
