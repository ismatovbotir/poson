@props(['locale'])

{{-- One locale's fields inside <x-admin.locale-tabs>. Hidden with x-show (stays in the DOM for wire:model). --}}
<div
    role="tabpanel"
    lang="{{ $locale }}"
    x-show="locale === @js($locale)"
    x-bind:id="$el.closest('[data-tabs-id]').dataset.tabsId + '-panel-{{ $locale }}'"
    x-bind:aria-labelledby="$el.closest('[data-tabs-id]').dataset.tabsId + '-tab-{{ $locale }}'"
    @if ($locale !== config('app.supported_locales')[0]) x-cloak @endif
    {{ $attributes->class(['space-y-5']) }}
>
    {{ $slot }}
</div>
