{{-- TEMPORARY: replaced by the Livewire home page in T5 (hero, categories, products, services, lead form). --}}
<x-layouts::site :title="__('site.seo.home.title')" :description="__('site.seo.home.description')">
    <section class="bg-linear-to-b from-bg-tint to-white py-12 md:py-16">
        <div class="site-container">
            <p class="mb-5 inline-flex items-center gap-2 rounded-full border border-line bg-white px-3.5 py-1.5 text-[13px] font-semibold text-brand-600">
                <i class="size-2 rounded-full bg-ok" aria-hidden="true"></i>
                {{ __('site.home.hero.eyebrow') }}
            </p>
            <h1 class="max-w-3xl text-4xl leading-[1.05] font-extrabold tracking-[-0.035em] lg:text-[54px]">
                {{ __('site.home.hero.title_before') }}<span class="text-brand-600">{{ __('site.home.hero.title_highlight') }}</span>{{ __('site.home.hero.title_after') }}
            </h1>
            <p class="mt-5 max-w-[520px] text-lg text-ink-2">{{ __('site.home.hero.text') }}</p>
        </div>
    </section>
</x-layouts::site>
