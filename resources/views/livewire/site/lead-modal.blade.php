{{--
    Quote request modal (App\Livewire\Site\LeadModal, plan 42). No template in
    .claude/html-templates/site: built in the lead form's visual language (white box, brand
    buttons). The page render is only this hidden shell (no form fields, QM-12); the form is
    mounted by open(). Alpine state: posonLeadModal in resources/js/lead-modal.js (opens at
    once with a loading state, closes the chat panel, Esc / backdrop / Close, focus returns to
    the trigger). Bottom sheet below sm, centred from sm; z-[65] above the chat (z-[55]).
    Every text is escaped ({{ }}), the product label is rendered by the child (QM-9).
--}}
<div
    x-data="posonLeadModal"
    x-on:poson-lead-modal.window="show($event.detail)"
    x-on:lead-modal-close.window="hide()"
    x-on:keydown.escape.window="if (open) hide()"
    data-lead-modal-root
>
    <div
        x-show="open"
        x-cloak
        x-transition.opacity.duration.150ms
        x-on:click.self="hide()"
        class="fixed inset-0 z-[65] flex items-end justify-center bg-ink/50 sm:items-center sm:p-6"
    >
        <div
            role="dialog"
            aria-modal="true"
            aria-labelledby="lead-modal-title"
            x-trap.inert.noscroll.noautofocus="open"
            class="flex max-h-[90dvh] w-full flex-col overflow-hidden rounded-t-[24px] bg-white shadow-card sm:max-h-[calc(100dvh-3rem)] sm:max-w-lg sm:rounded-box-lg"
            data-lead-modal-dialog
        >
            <div class="flex items-start justify-between gap-4 px-5 pt-5 pb-3 sm:px-7 sm:pt-7">
                <h2 id="lead-modal-title" x-ref="title" tabindex="-1" class="text-[22px] leading-[1.2] font-bold tracking-[-0.02em] focus:outline-none">
                    <span x-show="! loading">{{ $title }}</span>
                    <span x-show="loading" x-cloak>{{ __('site.lead_modal.loading') }}</span>
                </h2>
                <button
                    type="button"
                    x-on:click="hide()"
                    class="-mt-1.5 -mr-2 grid size-11 shrink-0 place-items-center rounded-btn text-ink transition-colors hover:bg-bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    data-lead-modal-close
                >
                    <x-site.icon name="close" size="size-6" />
                    <span class="sr-only">{{ __('site.lead_modal.close') }}</span>
                </button>
            </div>

            <div class="overflow-y-auto overscroll-contain px-5 pb-[calc(1.25rem+env(safe-area-inset-bottom))] sm:px-7 sm:pb-7">
                <div x-show="loading" x-cloak class="grid place-items-center py-12" aria-hidden="true">
                    <span class="size-9 animate-spin rounded-full border-[3px] border-line border-t-brand-600"></span>
                </div>

                <div x-show="! loading" data-lead-modal-body>
                    @if ($kind !== null)
                        @if ($kind !== \App\Livewire\Site\LeadModal::KIND_CART)
                            <p class="mb-4 text-ink-2">{{ __('site.lead_modal.intro') }}</p>
                        @else
                            <p class="mb-4 text-ink-2">{{ __('checkout.cart.quote.text') }}</p>
                        @endif

                        @if ($productUnavailable)
                            <p class="mb-4 rounded-btn border border-warn/30 bg-warn/5 px-4 py-3 text-sm font-medium text-ink" data-lead-modal-unavailable>{{ __('site.lead_form.product_unavailable') }}</p>
                        @endif

                        @if ($kind === \App\Livewire\Site\LeadModal::KIND_CART)
                            @if ($cartEmpty)
                                <p class="rounded-btn border border-danger/30 bg-danger/5 px-4 py-3 text-sm font-medium text-danger" data-lead-modal-cart-empty>{{ __('checkout.cart.errors.nothing_to_quote') }}</p>
                            @else
                                <livewire:site.cart.quote-form
                                    :page-url="$pageUrl"
                                    :locale="$locale"
                                    :attribution="$attribution"
                                    id-prefix="lead-modal-cart"
                                    :in-modal="true"
                                    :key="'lead-modal-cart-'.$formKey"
                                />
                            @endif
                        @else
                            <livewire:site.lead-form
                                :product-id="$productId"
                                :page-url="$pageUrl"
                                :locale="$locale"
                                :attribution="$attribution"
                                :roll-type-id="$rollTypeId"
                                :rolls="$rolls"
                                id-prefix="lead-modal"
                                :in-modal="true"
                                :key="'lead-modal-form-'.$formKey"
                            />
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
