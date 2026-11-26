@props(['name' => null, 'size' => 'size-5'])

{{-- Mini App icon from the same-origin sprite (App\Support\Telegram\MiniApp\TmaIcons, plan 45 A2). Decorative: the parent carries the accessible name. --}}
<svg {{ $attributes->class([$size, 'shrink-0 fill-none stroke-current']) }} stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><use href="{{ \App\Support\Telegram\MiniApp\TmaIcons::href($name) }}"/></svg>
