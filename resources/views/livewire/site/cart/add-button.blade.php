{{--
    "Add to cart" with a quantity stepper (App\Livewire\Site\Cart\AddButton), in the
    product page's buy box. Same stepper as the roll picker. No prices here.
--}}
<div class="grid w-full gap-2.5" data-add-button>
    @if ($available)
        <div class="flex flex-col gap-2.5 sm:flex-row">
            <div class="flex items-center gap-2">
                <label for="add-qty-{{ $productId }}" class="sr-only">{{ __('checkout.cart.quantity') }}</label>
                <button type="button" wire:click="decrement" @disabled($qty <= $minQty) class="grid size-[54px] place-items-center rounded-btn border-[1.5px] border-line text-lg font-semibold transition-colors hover:border-brand disabled:opacity-40" aria-label="{{ __('checkout.cart.decrease') }}">−</button>
                <input id="add-qty-{{ $productId }}" type="number" wire:model.live.debounce.400ms="qty" min="{{ $minQty }}" max="{{ $maxQty }}" step="1" inputmode="numeric" class="h-[54px] w-20 rounded-btn border-[1.5px] border-line bg-white px-2 text-center font-semibold tabular-nums focus:border-brand-600 focus:outline-none" data-qty>
                <button type="button" wire:click="increment" @disabled($qty >= $maxQty) class="grid size-[54px] place-items-center rounded-btn border-[1.5px] border-line text-lg font-semibold transition-colors hover:border-brand disabled:opacity-40" aria-label="{{ __('checkout.cart.increase') }}">+</button>
            </div>

            <button
                type="button"
                wire:click="add"
                wire:loading.attr="disabled"
                wire:target="add"
                class="inline-flex h-[54px] flex-1 items-center justify-center gap-2 rounded-btn bg-brand-600 px-[30px] text-base font-semibold text-white transition-colors hover:bg-brand-700 disabled:cursor-wait disabled:opacity-80"
                data-cta="cart"
            >
                <x-site.icon name="cart" />
                {{ __('site.card.add_to_cart') }}
            </button>
        </div>

        @if ($minQty > 1)
            <p class="text-[13px] text-ink-2" data-min-qty>{{ __('checkout.cart.min_hint', ['count' => \App\Support\NumberFormat::format($minQty), 'unit' => $unit]) }}</p>
        @endif

        <div role="status" aria-live="polite">
            @if ($added)
                <p class="flex flex-wrap items-center gap-x-2 text-sm font-medium text-brand-600" data-added>
                    <x-site.icon name="check" size="size-4" />
                    {{ __('checkout.cart.added') }}
                    <a href="{{ route('site.cart') }}" class="underline hover:text-brand-700">{{ __('checkout.cart.go_to_cart') }}</a>
                </p>
            @endif
        </div>

        @error('cart')
            <p role="alert" class="text-sm font-medium text-danger" data-cart-error>{{ $message }}</p>
        @enderror
    @endif
</div>
