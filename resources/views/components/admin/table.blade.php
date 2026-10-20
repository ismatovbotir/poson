{{--
    Desktop table (lg and up); pair it with <x-admin.mobile-list> for phones.
    Slots: "head" (<th> cells) and the default slot (<tr> rows).
--}}
<div {{ $attributes->class(['hidden overflow-hidden rounded-2xl border border-slate-200 bg-white lg:block']) }}>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            @isset($caption)
                <caption class="sr-only">{{ $caption }}</caption>
            @endisset
            <thead class="border-b border-slate-200 bg-slate-50/70 text-xs font-semibold uppercase tracking-wide text-slate-500">
                <tr>{{ $head }}</tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                {{ $slot }}
            </tbody>
        </table>
    </div>
</div>
