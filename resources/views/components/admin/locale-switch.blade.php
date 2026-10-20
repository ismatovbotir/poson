@props(['variant' => 'menu'])

{{--
    Admin UI language switch: POST admin.locale (users.locale for staff, the session on the login page).
    variant "menu": globe button + dropdown (top bar); "segmented": UZ | RU | EN pills (login page).
--}}
@php
    $current = app()->getLocale();
    $locales = config('app.supported_locales');
@endphp

@if ($variant === 'segmented')
    <div {{ $attributes->class(['flex rounded-lg bg-slate-100 p-1 text-xs font-bold']) }} role="group" aria-label="{{ __('admin.topbar.ui_language') }}">
        @foreach ($locales as $locale)
            <form method="POST" action="{{ route('admin.locale') }}">
                @csrf
                <input type="hidden" name="locale" value="{{ $locale }}">
                <button
                    type="submit"
                    lang="{{ $locale }}"
                    title="{{ __("admin.locale.{$locale}", [], $locale) }}"
                    @if ($locale === $current) aria-current="true" @endif
                    @class([
                        'rounded-md px-2.5 py-1 uppercase',
                        'bg-white shadow-sm' => $locale === $current,
                        'text-slate-500 hover:text-slate-800' => $locale !== $current,
                    ])
                ><span class="sr-only">{{ __("admin.locale.{$locale}", [], $locale) }}</span><span aria-hidden="true">{{ $locale }}</span></button>
            </form>
        @endforeach
    </div>
@else
    <div x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false" {{ $attributes->class('relative') }}>
        <button type="button" x-on:click="open = !open" x-bind:aria-expanded="open.toString()" aria-haspopup="true" class="flex h-10 items-center gap-1 rounded-lg px-2 text-sm font-semibold text-slate-600 hover:bg-slate-100 sm:px-2.5" aria-label="{{ __('admin.topbar.ui_language') }}">
            <x-admin.icon name="globe" /><span class="hidden uppercase sm:inline">{{ $current }}</span>
        </button>
        <div x-cloak x-show="open" x-transition.origin.top.right class="absolute right-0 mt-2 w-44 rounded-xl border border-slate-200 bg-white p-1.5 shadow-xl shadow-slate-900/10">
            @foreach ($locales as $locale)
                <form method="POST" action="{{ route('admin.locale') }}">
                    @csrf
                    <input type="hidden" name="locale" value="{{ $locale }}">
                    <button
                        type="submit"
                        lang="{{ $locale }}"
                        @if ($locale === $current) aria-current="true" @endif
                        @class([
                            'flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm hover:bg-slate-50',
                            'font-medium text-slate-900' => $locale === $current,
                            'text-slate-600' => $locale !== $current,
                        ])
                    >
                        {{ __("admin.locale.{$locale}", [], $locale) }}
                        @if ($locale === $current)<x-admin.icon name="check" size="size-4" class="text-brand-600" />@endif
                    </button>
                </form>
            @endforeach
        </div>
    </div>
@endif
