<form method="GET" action="{{ route('tma.search') }}" role="search" class="flex gap-2" data-search-form>
    <label for="tma-search" class="sr-only">{{ __('tma.search.label') }}</label>
    <input id="tma-search" type="search" name="q" value="{{ $term ?? '' }}" maxlength="{{ \App\Actions\Chat\SearchProducts::MAX_LENGTH }}" placeholder="{{ __('tma.search.placeholder') }}" class="tma-input" enterkeyhint="search">
    <button type="submit" class="tma-btn w-auto">{{ __('tma.search.submit') }}</button>
</form>
