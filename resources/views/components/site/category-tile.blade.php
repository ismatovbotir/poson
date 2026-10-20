@props(['tile'])

{{-- Category tile (App\Data\Site\CategoryTile). --}}
<a
    href="{{ $tile->url }}"
    {{ $attributes->class('group block rounded-box-lg border border-line bg-white px-[18px] py-[22px] text-center transition hover:-translate-y-[3px] hover:border-brand hover:shadow-card motion-reduce:hover:translate-y-0') }}
>
    <span class="mx-auto mb-3.5 grid size-16 place-items-center rounded-2xl bg-bg-tint text-brand-600">
        <x-site.icon :name="$tile->icon" size="size-[30px]" />
    </span>
    <b class="block text-[15px] leading-[1.3] font-semibold text-ink group-hover:text-brand-600">{{ $tile->name }}</b>
    @if ($tile->productsCount !== null)
        <small class="text-sm text-ink-2">{{ trans_choice('site.card.products_count', $tile->productsCount, ['count' => $tile->productsCount]) }}</small>
    @endif
</a>
