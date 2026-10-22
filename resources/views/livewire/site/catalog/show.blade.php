{{--
    Product page, ported from .claude/html-templates/site/product.html.
    Changes vs. the template (brand rule, plan 03): no rating or reviews, no quantity
    price tiers, no "buy in 1 click", no favorites, no old price. The site shows the
    Retail price only (or "price on request"). The buttons ($ctas) come from
    App\Support\Catalog\ProductCtas: "Try" (partner link, new tab) + "Contact us", or
    "Add to cart" + "Request a quote", or one lead-form button (#quote). A documents tab,
    software details ($software) and related products by type are added.
--}}
@php
    $tabs = array_filter([
        'description' => true,
        'specs' => true,
        'documents' => $documents !== [],
        'delivery' => true,
    ]);
    $shortDescription = (string) $product->short_description;
    $proseClass = 'text-ink-2 [&_a]:font-medium [&_a]:text-brand-600 [&_a]:underline [&_h2]:mt-6 [&_h2]:mb-2.5 [&_h2]:text-xl [&_h2]:font-bold [&_h2]:text-ink [&_h3]:mt-5 [&_h3]:mb-2.5 [&_h3]:text-lg [&_h3]:font-bold [&_h3]:text-ink [&_li]:mb-2 [&_ol]:mb-3.5 [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:mb-3.5 [&_strong]:text-ink [&_ul]:mb-3.5 [&_ul]:list-disc [&_ul]:pl-5 [&_li]:marker:text-brand';
@endphp

<div class="site-container">
    @push('head')
        @foreach ($jsonLd as $block)
            <script type="application/ld+json">{!! $block !!}</script>
        @endforeach
    @endpush

    {{-- Room under the footer for the fixed mobile bar, so it never covers the footer. --}}
    @push('after-footer')
        <div class="h-[72px] bg-ink md:hidden" aria-hidden="true" data-sticky-bar-spacer></div>
    @endpush

    <x-site.breadcrumbs :items="$breadcrumbs" />

    <div class="grid items-start gap-8 md:grid-cols-[1.05fr_0.95fr] md:gap-12">
        {{-- Gallery --}}
        <section
            class="grid gap-3.5 md:sticky md:top-36 md:grid-cols-[84px_1fr]"
            aria-label="{{ __('site.product.gallery') }}"
            x-data="{ active: 0, images: @js($images) }"
        >
            <div class="relative grid aspect-square place-items-center overflow-hidden rounded-box-lg border border-line bg-bg-soft md:order-2">
                @if ($product->badge)
                    <span @class([
                        'absolute top-3 left-3 z-10 rounded-md px-2.5 py-0.5 text-xs font-bold',
                        'bg-warn text-ink' => $product->badge === \App\Enums\ProductBadge::Hit,
                        'bg-brand-600 text-white' => $product->badge === \App\Enums\ProductBadge::New,
                    ]) data-badge="{{ $product->badge->value }}">{{ __('site.badges.'.$product->badge->value) }}</span>
                @endif

                @if ($images !== [])
                    <img
                        src="{{ $images[0]['large'] }}"
                        alt="{{ $images[0]['alt'] }}"
                        x-bind:src="images[active].large"
                        x-bind:alt="images[active].alt"
                        width="1200"
                        height="1200"
                        fetchpriority="high"
                        class="size-[86%] object-contain"
                    >
                @else
                    <svg viewBox="0 0 120 120" class="size-[62%]" aria-hidden="true" focusable="false"><use href="#{{ $placeholder }}" /></svg>
                @endif
            </div>

            @if (count($images) > 1)
                <div class="grid grid-cols-4 content-start gap-2.5 md:order-1 md:grid-cols-1">
                    @foreach ($images as $image)
                        <button
                            type="button"
                            wire:key="thumb-{{ $loop->index }}"
                            x-on:click="active = {{ $loop->index }}"
                            x-bind:aria-pressed="(active === {{ $loop->index }}).toString()"
                            x-bind:class="active === {{ $loop->index }} ? 'border-brand' : 'border-line'"
                            class="grid aspect-square place-items-center rounded-box border-[1.5px] border-line bg-bg-soft p-2 transition-colors hover:border-brand"
                        >
                            <img src="{{ $image['thumb'] }}" alt="" width="84" height="84" loading="lazy" class="max-h-full object-contain">
                            <span class="sr-only">{{ __('site.product.image', ['number' => $loop->iteration]) }}</span>
                        </button>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Summary --}}
        <div>
            <span class="text-xs font-semibold tracking-[0.05em] text-ink-2 uppercase">{{ $categoryName }}</span>
            <h1 class="mt-2.5 mb-3.5 text-[25px] leading-[1.2] font-bold tracking-[-0.02em] md:text-[30px]">{{ $product->name }}</h1>

            <dl class="flex flex-wrap items-center gap-x-[18px] gap-y-1 text-[13px] text-ink-2">
                <div class="flex gap-1"><dt>{{ __('site.product.sku') }}:</dt><dd class="font-medium text-ink" data-sku>{{ $product->sku }}</dd></div>
                @if ($brand)
                    <div class="flex gap-1"><dt>{{ __('site.product.brand') }}:</dt><dd class="font-medium text-ink">{{ $brand->name }}</dd></div>
                @endif
                @if ($product->showsStockStatus())
                    <div><dt class="sr-only">{{ __('site.catalog.stock') }}</dt><dd><x-site.stock-badge :status="$product->stock_status" /></dd></div>
                @endif
            </dl>

            @if ($shortDescription !== '')
                <p class="mt-4 text-ink-2">{{ $shortDescription }}</p>
            @endif

            <div class="my-[22px] rounded-box-lg border border-line bg-white p-6 shadow-card" data-buybox>
                @if ($product->price !== null)
                    <p class="text-[30px] leading-[1.1] font-extrabold tracking-[-0.02em] md:text-4xl" data-price>{{ \App\Support\Money::format($product->price) }}</p>
                @else
                    <p class="text-2xl font-bold text-ink" data-price>{{ __('site.card.price_on_request') }}</p>
                    <p class="mt-1.5 text-sm text-ink-2">{{ __('site.product.price_on_request_note') }}</p>
                @endif

                <div class="mt-4 flex flex-col gap-2.5 sm:flex-row" data-ctas>
                    @foreach ($ctas as $cta)
                        <x-site.product-cta :cta="$cta" size="page" :href="$cta->pageHref()" wire:key="cta-{{ $cta->kind->value }}" />
                    @endforeach
                </div>
            </div>

            @if ($keySpecs !== [])
                <h2 class="sr-only">{{ __('site.product.key_specs') }}</h2>
                <dl class="grid grid-cols-2 gap-2.5" data-key-specs>
                    @foreach ($keySpecs as $spec)
                        <div class="rounded-btn bg-bg-soft px-3.5 py-2.5 text-[13px]" wire:key="key-spec-{{ $loop->index }}">
                            <dt class="text-ink-3">{{ $spec['label'] }}</dt>
                            <dd class="font-semibold">{{ $spec['value'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif

            @if ($software !== [])
                @include('livewire.site.catalog.partials.software-details', ['software' => $software])
            @endif

            <ul class="mt-5 grid gap-3 text-sm text-ink-2">
                <li class="flex items-center gap-3"><x-site.icon name="truck" class="text-brand-600" />{{ __('site.home.hero.badge_delivery_text') }}</li>
                <li class="flex items-center gap-3">
                    <x-site.icon name="shield" class="text-brand-600" />
                    @if ($product->warranty_months)
                        {{ __('site.product.delivery.warranty', ['months' => trans_choice('site.product.months', $product->warranty_months, ['count' => $product->warranty_months])]) }}
                    @else
                        {{ __('site.product.delivery.warranty_on_request') }}
                    @endif
                </li>
                <li class="flex items-center gap-3"><x-site.icon name="wrench" class="text-brand-600" />{{ __('site.product.delivery.setup') }}</li>
            </ul>
        </div>
    </div>

    {{-- Tabs --}}
    <section class="mt-12 md:mt-16" x-data="{ tab: 'description' }" aria-label="{{ __('site.product.tabs.label') }}">
        <div class="flex gap-1.5 overflow-x-auto border-b border-line [scrollbar-width:none]" role="tablist">
            @foreach (array_keys($tabs) as $key)
                <button
                    type="button"
                    id="tab-{{ $key }}"
                    role="tab"
                    aria-controls="panel-{{ $key }}"
                    x-bind:aria-selected="(tab === '{{ $key }}').toString()"
                    x-bind:tabindex="tab === '{{ $key }}' ? 0 : -1"
                    x-on:click="tab = '{{ $key }}'"
                    x-bind:class="tab === '{{ $key }}' ? 'border-brand text-brand-600' : 'border-transparent text-ink-2'"
                    class="-mb-px border-b-[3px] px-5 py-3.5 font-semibold whitespace-nowrap transition-colors hover:text-brand-600"
                >{{ __('site.product.tabs.'.$key) }}</button>
            @endforeach
        </div>

        <div id="panel-description" role="tabpanel" aria-labelledby="tab-description" x-show="tab === 'description'" class="max-w-[900px] py-8">
            @if (trim(strip_tags($description)) !== '')
                <div class="{{ $proseClass }}" data-description>{!! $description !!}</div>
            @else
                <p class="text-ink-2">{{ __('site.product.no_description') }}</p>
            @endif
        </div>

        <div id="panel-specs" role="tabpanel" aria-labelledby="tab-specs" x-show="tab === 'specs'" x-cloak class="max-w-[900px] py-8">
            <table class="w-full border-collapse text-sm" data-specs>
                <caption class="sr-only">{{ __('site.product.tabs.specs') }}</caption>
                <tbody>
                    @if ($brand)
                        <tr class="border-b border-line"><th scope="row" class="w-[42%] py-[13px] pr-4 text-left font-normal text-ink-3">{{ __('site.product.brand') }}</th><td class="py-[13px] font-medium">{{ $brand->name }}</td></tr>
                    @endif
                    @if ($product->model)
                        <tr class="border-b border-line"><th scope="row" class="w-[42%] py-[13px] pr-4 text-left font-normal text-ink-3">{{ __('site.product.model') }}</th><td class="py-[13px] font-medium">{{ $product->model }}</td></tr>
                    @endif
                    <tr class="border-b border-line"><th scope="row" class="w-[42%] py-[13px] pr-4 text-left font-normal text-ink-3">{{ __('site.product.sku') }}</th><td class="py-[13px] font-medium">{{ $product->sku }}</td></tr>
                    @foreach ($specs as $spec)
                        <tr class="border-b border-line" wire:key="spec-{{ $loop->index }}" data-spec-row>
                            <th scope="row" class="w-[42%] py-[13px] pr-4 text-left font-normal text-ink-3">{{ $spec['label'] }}</th>
                            <td class="py-[13px] font-medium">{{ $spec['value'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($documents !== [])
            <div id="panel-documents" role="tabpanel" aria-labelledby="tab-documents" x-show="tab === 'documents'" x-cloak class="max-w-[900px] py-8">
                <ul class="grid gap-3" data-documents>
                    @foreach ($documents as $document)
                        <li class="flex flex-wrap items-center gap-3 rounded-box border border-line p-4" wire:key="document-{{ $loop->index }}">
                            <span class="grid size-11 place-items-center rounded-btn bg-bg-tint text-brand-600"><x-site.icon name="doc" /></span>
                            <span class="min-w-0 flex-1">
                                <span class="block font-semibold">{{ $document['title'] }}</span>
                                <span class="text-[13px] text-ink-2">{{ $document['type'] }} · {{ $document['kind'] }}@if ($document['locale']) · {{ $document['locale'] }}@endif</span>
                                @if ($document['sha256'])
                                    <span class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-ink-3" x-data="{ copied: false }" data-sha256="{{ $document['sha256'] }}">
                                        <span>{{ __('site.product.checksum') }}:</span>
                                        <code class="font-mono text-ink-2" title="{{ $document['sha256'] }}">{{ substr($document['sha256'], 0, 12) }}…</code>
                                        <button
                                            type="button"
                                            class="rounded px-1.5 py-0.5 font-semibold text-brand-600 hover:bg-brand-50"
                                            x-on:click="navigator.clipboard.writeText(@js($document['sha256'])).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
                                        >
                                            <span x-show="! copied">{{ __('site.product.copy') }}</span>
                                            <span x-show="copied" x-cloak>{{ __('site.product.copied') }}</span>
                                        </button>
                                    </span>
                                @endif
                            </span>
                            @if ($document['external'])
                                <a href="{{ $document['url'] }}" target="_blank" rel="noopener nofollow" class="inline-flex h-10 items-center rounded-btn border-[1.5px] border-line px-4 text-sm font-semibold transition-colors hover:border-brand hover:text-brand-600">
                                    {{ __('site.product.open_link') }}<span class="sr-only"> ({{ __('site.product.new_tab') }})</span>
                                </a>
                            @else
                                <a href="{{ $document['url'] }}" rel="nofollow" download class="inline-flex h-10 items-center rounded-btn bg-brand-50 px-4 text-sm font-semibold text-brand-600 transition-colors hover:bg-brand-100">
                                    {{ __('site.product.download') }}
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div id="panel-delivery" role="tabpanel" aria-labelledby="tab-delivery" x-show="tab === 'delivery'" x-cloak class="max-w-[900px] py-8">
            <ul class="grid gap-3 text-ink-2">
                <li class="flex gap-3"><x-site.icon name="truck" class="mt-0.5 text-brand-600" />{{ __('site.product.delivery.shipping') }}</li>
                <li class="flex gap-3"><x-site.icon name="doc" class="mt-0.5 text-brand-600" />{{ __('site.product.delivery.payment') }}</li>
                <li class="flex gap-3">
                    <x-site.icon name="shield" class="mt-0.5 text-brand-600" />
                    @if ($product->warranty_months)
                        {{ __('site.product.delivery.warranty', ['months' => trans_choice('site.product.months', $product->warranty_months, ['count' => $product->warranty_months])]) }}
                    @else
                        {{ __('site.product.delivery.warranty_on_request') }}
                    @endif
                </li>
                <li class="flex gap-3"><x-site.icon name="wrench" class="mt-0.5 text-brand-600" />{{ __('site.product.delivery.service') }}</li>
            </ul>
        </div>
    </section>

    {{-- Related products --}}
    @foreach ($related as $type => $cards)
        <section class="mt-10" aria-labelledby="related-{{ $type }}" wire:key="related-{{ $type }}" data-related="{{ $type }}">
            <x-site.section-head :id="'related-'.$type" :title="__('site.product.related.'.$type)" />
            <div class="grid grid-cols-2 gap-3 sm:gap-5 md:grid-cols-3 lg:grid-cols-4">
                @foreach ($cards as $card)
                    <x-site.product-card :product="$card" wire:key="related-{{ $type }}-{{ $card->slug }}" />
                @endforeach
            </div>
        </section>
    @endforeach

    {{-- FAQ: the product's, else its category's --}}
    @if ($faq !== [])
        <x-site.faq class="mt-12 md:mt-[72px]" id="product-faq-title" :items="$faq" :title="__('site.faq.title')" />
    @endif

    {{-- Quote form --}}
    <section id="quote" class="mt-12 scroll-mt-28 md:mt-[72px]" aria-labelledby="quote-title">
        <div class="grid items-center gap-8 rounded-[28px] bg-bg-tint px-6 py-8 md:grid-cols-2 md:gap-12 md:p-14">
            <div>
                <h2 id="quote-title" class="mb-3 text-[28px] leading-[1.15] font-bold tracking-[-0.02em] md:text-[32px]">{{ __('site.product.quote.title') }}</h2>
                <p class="text-ink-2">{{ __('site.product.quote.text') }}</p>
            </div>
            <livewire:site.lead-form :product-id="$product->id" />
        </div>
    </section>

    {{-- Mobile sticky bar: the price (or "price on request") and the primary CTA only. --}}
    @php($primaryCta = collect($ctas)->firstWhere('primary', true) ?? ($ctas[0] ?? null))
    <div class="fixed inset-x-0 bottom-0 z-30 flex items-center gap-3 border-t border-line bg-white px-4 py-2.5 md:hidden" data-sticky-bar>
        <p @class(['flex-1 leading-tight', 'text-xl font-extrabold tracking-[-0.02em]' => $product->price !== null, 'text-sm font-semibold text-ink-2' => $product->price === null])>
            {{ $product->price !== null ? \App\Support\Money::format($product->price) : __('site.card.price_on_request') }}
        </p>
        @if ($primaryCta)
            <x-site.product-cta :cta="$primaryCta" size="bar" :href="$primaryCta->pageHref()" />
        @endif
    </div>
</div>
