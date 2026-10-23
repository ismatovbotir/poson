{{-- News post state badge (NewsState: draft / scheduled / published) plus the locales it is shown in. --}}
@php($state = $post->state())
<x-admin.badge :color="$state->color()" pill dot data-news-state-badge="{{ $state->value }}">{{ $state->label() }}</x-admin.badge>
@foreach (config('app.supported_locales') as $locale)
    @if (in_array($locale, (array) $post->locales, true))
        <x-admin.badge color="outline" pill data-news-locale="{{ $locale }}">{{ strtoupper($locale) }}</x-admin.badge>
    @endif
@endforeach
