{{-- Which locales have a product name. --}}
<span class="flex gap-1">
    @foreach (config('app.supported_locales') as $locale)
        @php($translated = filled($product->getTranslation('name', $locale, false)))
        <span
            @class(['rounded px-1.5 py-0.5 text-[10px] font-bold uppercase', 'bg-brand-50 text-brand-700' => $translated, 'bg-slate-100 text-slate-400 line-through' => ! $translated])
            title="{{ $translated ? __('admin.products.translation_complete') : __('admin.form.translation_missing') }}"
        >{{ $locale }}</span>
    @endforeach
</span>
