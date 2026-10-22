@php
    $locales = config('app.supported_locales');
    $typeChoices = collect($types);
    $currentType = \App\Enums\ProductType::tryFrom($form->type);

    if ($currentType && ! $typeChoices->contains($currentType)) {
        $typeChoices->push($currentType);
    }

    $categoryChosen = filled($form->category_id) && isset($this->categoryOptions[(int) $form->category_id]);
    $categoryName = $categoryChosen ? \App\Support\Catalog\CategoryTree::name((int) $form->category_id) : null;
    $siteHost = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'poson.uz';
    $sections = [
        'main' => __('admin.products.sections.main'),
        'price' => __('admin.products.sections.price'),
        'specs' => __('admin.products.sections.specs'),
        'media' => __('admin.products.sections.images'),
        'docs' => __('admin.products.sections.documents'),
        'seo' => __('admin.catalog.seo'),
    ];
@endphp

<form wire:submit="save" novalidate>
    {{-- Header --}}
    <x-admin.page-header :title="$title" :back-href="route('admin.products.index')" :back-label="__('admin.products.title')">
        @if ($canSave)
            <x-slot:actions>
                <div class="hidden gap-2 lg:flex">
                    <x-admin.button variant="secondary" :href="route('admin.products.index')" wire:navigate>{{ __('admin.common.cancel') }}</x-admin.button>
                    <x-admin.button type="submit" icon="check" loading="save">{{ __('admin.common.save') }}</x-admin.button>
                </div>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    @if ($product)
        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-500">
            @include('livewire.admin.products.partials.status', ['product' => $product])
            <span>{{ __('admin.products.fields.sku') }} <span class="font-mono">{{ $product->sku }}</span></span>
            <span aria-hidden="true">·</span>
            <span>{{ __('admin.products.last_changed', ['date' => \App\Support\DateFormat::iso($product->updated_at, 'D MMM YYYY, HH:mm')]) }}</span>
        </div>
    @endif

    @if (! $canSave)
        <div class="mt-4 flex gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3.5 text-sm text-slate-700" role="status" data-read-only>
            <x-admin.icon name="lock" class="mt-0.5 shrink-0 text-slate-400" />
            <p>{{ __('admin.products.read_only') }}</p>
        </div>
    @elseif ($product?->is_active && $product->has_missing_specs)
        <div class="mt-4 flex gap-3 rounded-xl border border-amber-200 bg-amber-50 p-3.5 text-sm text-amber-900" role="status">
            <x-admin.icon name="alert" class="mt-0.5 shrink-0 text-warn" />
            <p>{{ __('admin.products.active_incomplete') }}</p>
        </div>
    @endif

    @error('form.specs')
        <div class="mt-4 flex gap-3 rounded-xl border border-red-200 bg-red-50 p-3.5 text-sm text-danger" role="alert" data-specs-error>
            <x-admin.icon name="alert" class="mt-0.5 shrink-0" />
            <p>{{ $message }}</p>
        </div>
    @enderror

    {{-- Section nav --}}
    <nav class="scrollbar-none sticky top-16 z-20 -mx-4 mt-4 overflow-x-auto border-b border-slate-200 bg-canvas/95 px-4 backdrop-blur sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8" aria-label="{{ __('admin.products.sections.label') }}">
        <div class="flex gap-5">
            @foreach ($sections as $anchor => $label)
                <a href="#s-{{ $anchor }}" class="-mb-px whitespace-nowrap border-b-2 border-transparent py-3 text-sm font-semibold text-slate-500 hover:border-slate-300 hover:text-slate-700">{{ $label }}</a>
            @endforeach
        </div>
    </nav>

    <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-3 lg:gap-6">
        {{-- ===== Main column ===== --}}
        <div class="min-w-0 space-y-4 lg:col-span-2 lg:space-y-6">

            {{-- Main --}}
            <x-admin.card id="s-main" class="scroll-mt-32" :title="__('admin.catalog.basic_info')">
                <x-admin.locale-tabs :error-keys="['form.name', 'form.short_description', 'form.description']" :label="__('admin.form.content_language')">
                    @foreach ($locales as $locale)
                        <x-admin.locale-panel :locale="$locale">
                            <x-admin.input wire:model.blur="form.name.{{ $locale }}" :label="__('admin.catalog.name')" :required="$locale === 'uz'" maxlength="255">
                                <x-slot:labelAside><span class="ml-auto"><x-admin.ai-button :field="'form.name.'.$locale" /></span></x-slot:labelAside>
                            </x-admin.input>
                            <x-admin.textarea wire:model="form.short_description.{{ $locale }}" :label="__('admin.products.fields.short_description')" rows="2" maxlength="500" :hint="__('admin.products.short_description_hint')">
                                <x-slot:labelAside>
                                    <span class="ml-auto flex items-center gap-2">
                                        <span class="text-xs font-medium tabular-nums text-slate-400" x-text="(($wire.form.short_description[@js($locale)] ?? '').length) + '/500'"></span>
                                        <x-admin.ai-button :field="'form.short_description.'.$locale" />
                                    </span>
                                </x-slot:labelAside>
                            </x-admin.textarea>
                            <x-admin.rich-text wire:model="form.description.{{ $locale }}" :label="__('admin.products.fields.description')">
                                <x-slot:labelAside><span class="ml-auto"><x-admin.ai-button :field="'form.description.'.$locale" /></span></x-slot:labelAside>
                            </x-admin.rich-text>
                        </x-admin.locale-panel>
                    @endforeach
                </x-admin.locale-tabs>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <x-admin.input wire:model="form.sku" :label="__('admin.products.fields.sku')" required maxlength="64" autocomplete="off" spellcheck="false" class="[&_input]:font-mono" :hint="__('admin.products.sku_hint')" />
                    <x-admin.input wire:model="form.model" :label="__('admin.products.fields.model')" maxlength="255" autocomplete="off" />
                </div>

                <div class="mt-5" x-data="{ original: @js($product?->slug), active: @js((bool) $product?->is_active) }">
                    <label for="f-form-slug" class="text-sm font-semibold">{{ __('admin.catalog.slug') }}</label>
                    <div @class([
                        'mt-1.5 flex h-11 overflow-hidden rounded-lg border focus-within:ring-4',
                        'border-slate-200 focus-within:border-brand-500 focus-within:ring-brand-500/15' => ! $errors->has('form.slug'),
                        'border-red-300 focus-within:border-danger focus-within:ring-red-500/15' => $errors->has('form.slug'),
                    ])>
                        <span class="hidden items-center border-r border-slate-200 bg-slate-50 px-3 text-sm text-slate-500 sm:flex">/product/</span>
                        <input id="f-form-slug" wire:model="form.slug" placeholder="{{ $form->autoSlug() ?: __('admin.catalog.slug_auto') }}" autocomplete="off" spellcheck="false" aria-describedby="f-form-slug-hint" class="min-w-0 flex-1 px-3 font-mono text-base outline-none sm:text-sm">
                    </div>
                    <p id="f-form-slug-hint" class="mt-1 text-xs text-slate-500">{{ __('admin.catalog.slug_hint') }}</p>
                    <p x-cloak x-show="original && active && $wire.form.slug !== original" class="mt-1.5 flex items-start gap-1.5 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800" role="status" data-slug-warning>
                        <x-admin.icon name="alert" size="size-4" class="shrink-0" />{{ __('admin.catalog.slug_changed_warning') }}
                    </p>
                    <x-admin.field-error name="form.slug" />
                </div>
            </x-admin.card>

            {{-- Price & ordering --}}
            <x-admin.card id="s-price" class="scroll-mt-32" :title="__('admin.products.sections.price')">
                <div class="space-y-6">
                    <fieldset>
                        <legend class="text-sm font-semibold">{{ __('admin.products.fields.sale_mode') }}</legend>
                        <div class="mt-2 grid gap-2 sm:grid-cols-3">
                            @foreach (\App\Enums\SaleMode::cases() as $mode)
                                <label class="relative flex cursor-pointer gap-3 rounded-xl border border-slate-200 p-3.5 hover:border-slate-300 has-checked:border-brand-600 has-checked:bg-brand-50/60 has-checked:ring-1 has-checked:ring-brand-600">
                                    <input type="radio" wire:model="form.sale_mode" value="{{ $mode->value }}" class="mt-0.5 size-4 accent-brand-600">
                                    <span>
                                        <span class="block text-sm font-semibold">{{ $mode->label() }}</span>
                                        <span class="block text-xs text-slate-500">{{ __('admin.products.sale_mode_hints.'.$mode->value) }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        <x-admin.field-error name="form.sale_mode" />
                    </fieldset>

                    <div>
                        <div class="grid gap-4 sm:grid-cols-3">
                            <x-admin.money-input wire:model="form.price" :label="__('enums.price_level.retail')" :hint="__('admin.products.price_hints.retail')" />
                            <x-admin.money-input wire:model="form.price_dealer" :label="__('enums.price_level.dealer')" :hint="__('admin.products.price_hints.fallback')" />
                            <x-admin.money-input wire:model="form.price_wholesale" :label="__('enums.price_level.wholesale')" :hint="__('admin.products.price_hints.fallback')" />
                        </div>
                        <p class="mt-2 flex items-start gap-1.5 text-xs text-slate-500"><x-admin.icon name="lock" size="size-4" class="shrink-0 text-slate-400" />{{ __('admin.products.price_hints.levels') }}</p>
                        <div x-cloak x-show="! String($wire.form.price ?? '').trim()" class="mt-3 flex gap-3 rounded-xl border border-sky-200 bg-sky-50 p-3.5 text-sm text-sky-900" role="status" data-price-on-request>
                            <x-admin.icon name="info" class="mt-0.5 shrink-0 text-sky-600" />
                            <p><b class="font-semibold">{{ __('admin.products.price_on_request') }}.</b> {{ __('admin.products.price_on_request_notice') }}</p>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <x-admin.select wire:model="form.unit" :label="__('admin.products.fields.unit')" :options="collect(\App\Enums\ProductUnit::cases())->mapWithKeys(fn ($unit) => [$unit->value => $unit->label()])->all()" required />
                        <x-admin.input wire:model="form.min_qty" type="number" min="1" inputmode="numeric" :label="__('admin.products.fields.min_qty')" :hint="__('admin.products.min_qty_hint')" required />
                        <x-admin.input wire:model="form.warranty_months" type="number" min="0" max="600" inputmode="numeric" :label="__('admin.products.fields.warranty_months')" :hint="__('admin.products.warranty_hint')" />
                    </div>

                    <x-admin.select wire:model="form.stock_status" :label="__('admin.products.fields.stock_status')" :options="collect(\App\Enums\StockStatus::cases())->mapWithKeys(fn ($status) => [$status->value => $status->label()])->all()" required class="sm:max-w-xs" />
                </div>
            </x-admin.card>

            {{-- Specs --}}
            @include('livewire.admin.products.partials.specs')

            {{-- Images --}}
            <section id="s-media" class="scroll-mt-32 rounded-2xl border border-slate-200 bg-white">
                <div class="border-b border-slate-100 px-4 py-3.5 sm:px-6"><h2 class="text-base font-semibold">{{ __('admin.products.sections.images') }}</h2></div>
                @if ($product)
                    <livewire:admin.products.images :product="$product" :key="'images-'.$product->id" />
                @else
                    <p class="p-4 text-sm text-slate-500 sm:p-6" data-save-first>{{ __('admin.products.save_first') }}</p>
                @endif
            </section>

            {{-- Documents --}}
            <section id="s-docs" class="scroll-mt-32 rounded-2xl border border-slate-200 bg-white">
                @if ($product)
                    <livewire:admin.products.documents :product="$product" :key="'documents-'.$product->id" />
                @else
                    <div class="border-b border-slate-100 px-4 py-3.5 sm:px-6"><h2 class="text-base font-semibold">{{ __('admin.products.sections.documents') }}</h2></div>
                    <p class="p-4 text-sm text-slate-500 sm:p-6">{{ __('admin.products.save_first') }}</p>
                @endif
            </section>

            {{-- SEO --}}
            <x-admin.card id="s-seo" class="scroll-mt-32" :title="__('admin.catalog.seo')" :description="__('admin.catalog.seo_help')">
                <x-admin.locale-tabs :error-keys="['form.meta_title', 'form.meta_description']" :label="__('admin.catalog.seo')">
                    @foreach ($locales as $locale)
                        <x-admin.locale-panel :locale="$locale">
                            <div class="rounded-xl border border-slate-200 bg-canvas p-4" data-seo-preview>
                                <p class="text-xs text-slate-500">{{ __('admin.products.seo_preview') }}</p>
                                <p class="mt-2 truncate text-xs text-slate-600">{{ $siteHost }} › {{ $locale }} › product › <span x-text="$wire.form.slug || @js($form->autoSlug()) || '…'"></span></p>
                                <p class="mt-0.5 line-clamp-1 text-lg leading-snug text-[#1a0dab]" x-text="$wire.form.meta_title[@js($locale)] || $wire.form.name[@js($locale)] || $wire.form.name.uz || @js(__('admin.products.seo_preview_title'))"></p>
                                <p class="mt-0.5 line-clamp-2 text-sm text-slate-600" x-text="$wire.form.meta_description[@js($locale)] || $wire.form.short_description[@js($locale)] || @js(__('admin.products.seo_preview_description'))"></p>
                            </div>
                            <x-admin.input wire:model="form.meta_title.{{ $locale }}" :label="__('admin.catalog.meta_title')" maxlength="255" :hint="__('admin.catalog.meta_title_hint')">
                                <x-slot:labelAside>
                                    <span class="ml-auto flex items-center gap-2">
                                        <span class="text-xs font-medium tabular-nums" x-bind:class="(($wire.form.meta_title[@js($locale)] ?? '').length > 60) ? 'text-danger' : 'text-slate-400'" x-text="(($wire.form.meta_title[@js($locale)] ?? '').length) + '/60'"></span>
                                        <x-admin.ai-button :field="'form.meta_title.'.$locale" />
                                    </span>
                                </x-slot:labelAside>
                            </x-admin.input>
                            <x-admin.textarea wire:model="form.meta_description.{{ $locale }}" :label="__('admin.catalog.meta_description')" rows="3" maxlength="500" :hint="__('admin.catalog.meta_description_hint')">
                                <x-slot:labelAside>
                                    <span class="ml-auto flex items-center gap-2">
                                        <span class="text-xs font-medium tabular-nums" x-bind:class="(($wire.form.meta_description[@js($locale)] ?? '').length > 160) ? 'text-danger' : 'text-slate-400'" x-text="(($wire.form.meta_description[@js($locale)] ?? '').length) + '/160'"></span>
                                        <x-admin.ai-button :field="'form.meta_description.'.$locale" />
                                    </span>
                                </x-slot:labelAside>
                            </x-admin.textarea>
                        </x-admin.locale-panel>
                    @endforeach
                </x-admin.locale-tabs>
            </x-admin.card>
        </div>

        {{-- ===== Side column ===== --}}
        <aside class="min-w-0 space-y-4 lg:sticky lg:top-32 lg:self-start lg:space-y-6">
            <x-admin.card :title="__('admin.products.sections.publish')">
                <div class="space-y-4">
                    <x-admin.toggle wire:model="form.is_active" :label="__('admin.catalog.is_active')" :description="__('admin.products.is_active_hint')" />
                    <x-admin.toggle wire:model="form.is_featured" :label="__('admin.products.fields.is_featured')" :description="__('admin.products.is_featured_hint')" />
                    <fieldset>
                        <legend class="text-sm font-semibold">{{ __('admin.products.fields.badge') }}</legend>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <label class="cursor-pointer">
                                <input type="radio" wire:model="form.badge" value="" class="peer sr-only">
                                <span class="inline-flex h-9 items-center rounded-lg border border-slate-200 px-3 text-sm font-medium text-slate-600 peer-checked:border-brand-600 peer-checked:bg-brand-50 peer-checked:text-brand-700 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500">{{ __('admin.products.no_badge') }}</span>
                            </label>
                            @foreach (\App\Enums\ProductBadge::cases() as $badge)
                                <label class="cursor-pointer">
                                    <input type="radio" wire:model="form.badge" value="{{ $badge->value }}" class="peer sr-only">
                                    <span class="inline-flex h-9 items-center rounded-lg border border-slate-200 px-3 text-sm font-medium text-slate-600 peer-checked:border-brand-600 peer-checked:bg-brand-50 peer-checked:text-brand-700 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500">{{ $badge->label() }}</span>
                                </label>
                            @endforeach
                        </div>
                        <x-admin.field-error name="form.badge" />
                    </fieldset>
                </div>
            </x-admin.card>

            <x-admin.card :title="__('admin.products.sections.classification')">
                <div class="space-y-4">
                    <x-admin.select wire:model="form.type" :label="__('admin.products.fields.type')" :options="$typeChoices->mapWithKeys(fn ($type) => [$type->value => $type->label()])->all()" required />
                    <div>
                        <x-admin.select wire:model.live="form.category_id" :label="__('admin.products.fields.category')" :placeholder="__('admin.common.choose')" :options="$this->categoryOptions" required />
                        <p class="mt-1 text-xs text-slate-500">{{ __('admin.products.category_hint') }}</p>
                    </div>
                    <x-admin.select wire:model="form.brand_id" :label="__('admin.products.fields.brand')" :placeholder="__('admin.products.no_brand')" :options="$this->brandOptions" />
                </div>
            </x-admin.card>

            <section class="rounded-2xl border border-slate-200 bg-white">
                @if ($product)
                    <livewire:admin.products.relations :product="$product" :key="'relations-'.$product->id" />
                @else
                    <div class="p-4 sm:p-5">
                        <h2 class="text-sm font-semibold">{{ __('admin.products.relations.title') }}</h2>
                        <p class="mt-1 text-xs text-slate-500">{{ __('admin.products.save_first') }}</p>
                    </div>
                @endif
            </section>

            @if ($canDelete)
                <section class="rounded-2xl border border-red-200 bg-white p-4 sm:p-5">
                    <h2 class="text-sm font-semibold text-danger">{{ __('admin.catalog.danger_zone') }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ __('admin.products.delete_help') }}</p>
                    <button
                        type="button"
                        x-on:click="$dispatch('confirm', {
                            title: @js(__('admin.products.delete_title')),
                            body: @js(__('admin.products.delete_body', ['name' => $product->name])),
                            label: @js(__('admin.common.delete')),
                            component: $wire.$id, method: 'delete',
                        })"
                        class="mt-3 h-10 w-full rounded-lg border border-red-200 text-sm font-semibold text-danger hover:bg-red-50"
                    >{{ __('admin.common.delete') }}</button>
                </section>
            @endif
        </aside>
    </div>

    @if ($canSave)
        <x-admin.sticky-save-bar :cancel-href="route('admin.products.index')" />
    @endif
</form>
