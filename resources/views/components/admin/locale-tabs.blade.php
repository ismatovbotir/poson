@props(['errorKeys' => [], 'label' => null])

{{--
    uz / ru / en tabs for translatable fields. Put one <x-admin.locale-panel locale="…">
    per locale in the slot. errorKeys: field prefixes such as ['form.name', 'form.description'];
    a tab gets a red dot when "<key>.<locale>" has an error, and the first tab with
    errors opens automatically.

    <x-admin.locale-tabs :error-keys="['form.name']">
        @foreach (config('app.supported_locales') as $locale)
            <x-admin.locale-panel :locale="$locale">
                <x-admin.input wire:model="form.name.{{ $locale }}" … />
            </x-admin.locale-panel>
        @endforeach
    </x-admin.locale-tabs>
--}}
@php
    $locales = config('app.supported_locales');
    $withErrors = collect($locales)
        ->filter(fn (string $locale): bool => collect($errorKeys)->contains(fn (string $key): bool => $errors->has("{$key}.{$locale}")))
        ->values()
        ->all();
    $initial = $withErrors[0] ?? $locales[0];
    $tabsId = 'lt-'.substr(md5(implode('|', $errorKeys).($label ?? '')), 0, 8);
@endphp

<div x-data="{ locale: @js($initial) }" {{ $attributes->class(['min-w-0']) }}>
    <div class="flex rounded-lg bg-slate-100 p-1" role="tablist" aria-label="{{ $label ?? __('admin.form.content_language') }}">
        @foreach ($locales as $locale)
            @php($hasErrors = in_array($locale, $withErrors, true))
            <button
                type="button"
                role="tab"
                id="{{ $tabsId }}-tab-{{ $locale }}"
                aria-controls="{{ $tabsId }}-panel-{{ $locale }}"
                x-on:click="locale = @js($locale)"
                x-bind:aria-selected="(locale === @js($locale)).toString()"
                x-bind:tabindex="locale === @js($locale) ? 0 : -1"
                x-on:keydown.arrow-right.prevent="$el.nextElementSibling?.click(); $el.nextElementSibling?.focus()"
                x-on:keydown.arrow-left.prevent="$el.previousElementSibling?.click(); $el.previousElementSibling?.focus()"
                x-bind:class="locale === @js($locale) ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                title="{{ __("admin.locale.{$locale}", [], $locale) }}"
                class="flex h-8 flex-1 items-center justify-center gap-1.5 rounded-md px-3 text-xs font-bold uppercase sm:flex-none"
            >
                <span>{{ $locale }}</span>
                @if ($hasErrors)
                    <span class="size-1.5 rounded-full bg-danger" aria-hidden="true"></span>
                    <span class="sr-only">({{ __('admin.form.locale_has_errors') }})</span>
                @endif
            </button>
        @endforeach
    </div>

    <div class="mt-4" data-tabs-id="{{ $tabsId }}">
        {{ $slot }}
    </div>
</div>
