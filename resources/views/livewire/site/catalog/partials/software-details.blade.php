{{--
    Software details on the product page (App\Support\Catalog\SoftwareSpecs::forProduct()):
    chips (platforms, interface languages, segment, licence model, integrations), the
    modules list and fiscal receipts. Values are admin-managed specs; nothing here is
    hard-coded copy. The vendor link is the "Try" button (brand website, tracked redirect).
--}}
<section class="mt-5 rounded-box-lg border border-line p-5" aria-labelledby="software-details-title" data-software-details>
    <h2 id="software-details-title" class="text-base font-bold">{{ __('site.product.software.title') }}</h2>

    @if ($software['chips'] !== [])
        <dl class="mt-3.5 grid gap-3">
            @foreach ($software['chips'] as $group)
                <div wire:key="software-{{ $group['code'] }}" data-software="{{ $group['code'] }}">
                    <dt class="text-[13px] text-ink-3">{{ $group['label'] }}</dt>
                    <dd class="mt-1.5 flex flex-wrap gap-1.5">
                        @foreach ($group['values'] as $value)
                            <span class="inline-flex items-center rounded-full bg-bg-tint px-3 py-1 text-[13px] font-semibold text-brand-700" data-chip>{{ $value }}</span>
                        @endforeach
                    </dd>
                </div>
            @endforeach
        </dl>
    @endif

    @if ($software['modules'])
        <div class="mt-4" data-software="modules">
            <h3 class="text-[13px] text-ink-3">{{ $software['modules']['label'] }}</h3>
            <ul class="mt-2 grid gap-1.5 text-sm sm:grid-cols-2">
                @foreach ($software['modules']['values'] as $module)
                    <li class="flex items-center gap-2"><x-site.icon name="check" size="size-4" class="text-brand-600" />{{ $module }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($software['online_cash_register'])
        <p class="mt-4 flex items-center gap-2 text-sm" data-software="online_cash_register">
            <span class="text-ink-3">{{ $software['online_cash_register']['label'] }}:</span>
            <span class="font-semibold">{{ $software['online_cash_register']['value'] ? __('site.product.yes') : __('site.product.no') }}</span>
        </p>
    @endif

</section>
