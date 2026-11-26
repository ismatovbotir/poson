<form method="GET" action="{{ route('tma.search') }}" role="search" class="relative" data-search-form>
    <label for="tma-search" class="sr-only">{{ __('tma.search.label') }}</label>
    <input id="tma-search" type="search" name="q" value="{{ $term ?? '' }}" maxlength="{{ \App\Actions\Chat\SearchProducts::MAX_LENGTH }}" placeholder="{{ __('tma.search.placeholder') }}" class="tma-input min-h-11 pr-12" enterkeyhint="search">
    <button type="submit" class="absolute inset-y-0 right-0 grid w-11 place-items-center text-tg-hint" aria-label="{{ __('tma.search.submit') }}"><x-tma.icon name="search" /></button>
</form>
