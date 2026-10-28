{{--
    Header cart link (App\Livewire\Site\Cart\Counter), from the template's header action
    with a badge. The notice toast shows "Added to cart" (or an error) after a card's
    "Add to cart" ("cart-notice" browser event).
--}}
<div class="shrink-0">
    <a
        href="{{ route('site.cart') }}"
        class="relative grid size-11 place-items-center rounded-btn text-ink transition-colors hover:bg-bg-soft hover:text-brand-600 md:size-[46px]"
        data-cart-counter
    >
        <x-site.icon name="cart" size="size-6" />
        <span class="sr-only">{{ __('site.header.cart') }}@if ($count > 0): {{ trans_choice('checkout.cart.lines', $count, ['count' => $count]) }}@endif</span>
        @if ($count > 0)
            <span class="absolute -top-0.5 -right-0.5 grid h-5 min-w-5 place-items-center rounded-full bg-brand-600 px-1 text-[11px] leading-none font-bold text-white" aria-hidden="true" data-cart-count>{{ $count > 99 ? '99+' : $count }}</span>
        @endif
    </a>

    <div
        wire:ignore
        x-data="{ show: false, message: '', error: false, timer: null }"
        x-on:cart-notice.window="message = $event.detail.message; error = $event.detail.error; show = true; clearTimeout(timer); timer = setTimeout(() => show = false, 4000)"
        class="pointer-events-none fixed inset-x-4 bottom-24 z-50 flex justify-center md:inset-x-auto md:right-6 md:bottom-6"
        role="status"
        aria-live="polite"
    >
        <div
            x-show="show"
            x-transition.opacity
            x-cloak
            class="pointer-events-auto flex items-center gap-3 rounded-box-lg border border-line bg-white px-4 py-3 text-sm font-medium shadow-card"
            x-bind:class="error ? 'text-danger' : 'text-ink'"
        >
            <span x-text="message"></span>
            <a x-show="! error" href="{{ route('site.cart') }}" class="font-semibold text-brand-600 underline hover:text-brand-700">{{ __('checkout.cart.go_to_cart') }}</a>
        </div>
    </div>
</div>
