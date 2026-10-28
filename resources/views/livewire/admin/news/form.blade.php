@php
    $locales = config('app.supported_locales');
    $pickerInput = 'h-10 w-full rounded-lg border border-slate-200 bg-white pl-9 pr-3 text-base outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 sm:text-sm';
@endphp

<form wire:submit="save" novalidate>
    <x-admin.page-header :title="$title" :back-href="route($kind->adminRoute('index'))" :back-label="$kind->adminText('title')">
        @if ($newsPost)
            <x-slot:breadcrumbs>/{{ $kind->segment() }}/{{ $newsPost->slug }}</x-slot:breadcrumbs>
        @endif
        @if ($canSave)
            <x-slot:actions>
                <div class="hidden gap-2 lg:flex">
                    <x-admin.button variant="secondary" :href="route($kind->adminRoute('index'))" wire:navigate>{{ __('admin.common.cancel') }}</x-admin.button>
                    <x-admin.button type="submit" icon="check" loading="save">{{ __('admin.common.save') }}</x-admin.button>
                </div>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-3 lg:gap-6">
        <div class="min-w-0 space-y-4 lg:col-span-2 lg:space-y-6">

            {{-- Content --}}
            <x-admin.card :title="__('admin.news.content')" :description="$kind->adminText('content_help')">
                <x-admin.locale-tabs :error-keys="['form.title', 'form.excerpt', 'form.body']" :label="__('admin.form.content_language')">
                    @foreach ($locales as $locale)
                        <x-admin.locale-panel :locale="$locale">
                            <x-admin.input wire:model.blur="form.title.{{ $locale }}" :label="__('admin.news.fields.title')" :required="$locale === 'uz'" maxlength="255" />
                            <x-admin.textarea wire:model="form.excerpt.{{ $locale }}" :label="__('admin.news.fields.excerpt')" rows="3" maxlength="500" :hint="$kind->adminText('excerpt_hint')" />
                            <x-admin.rich-text wire:model="form.body.{{ $locale }}" :label="__('admin.news.fields.body')" />
                        </x-admin.locale-panel>
                    @endforeach
                </x-admin.locale-tabs>

                <x-admin.slug-field class="mt-5" :prefix="'/'.$kind->segment().'/'" :slug="$newsPost?->slug" :preview="$form->autoSlug()" :hint="__('admin.news.slug_hint')" />
            </x-admin.card>

            {{-- Related products and categories --}}
            <x-admin.card :title="__('admin.news.related')" :description="$kind->adminText('related_help')">
                <div class="grid gap-6 md:grid-cols-2" x-on:keydown.enter="if ($event.target.tagName === 'INPUT' && $event.target.type === 'search') $event.preventDefault()">
                    <div data-news-products>
                        <h3 class="text-sm font-semibold">{{ __('admin.news.fields.products') }}</h3>
                        <ul class="mt-2 space-y-2">
                            @forelse ($this->selectedProducts as $product)
                                <li wire:key="news-product-{{ $product->id }}" class="flex items-center gap-2 rounded-lg border border-slate-200 p-2" data-news-product="{{ $product->sku }}">
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm">{{ $product->name }}</span>
                                        <span class="block truncate text-[11px] text-slate-400"><span class="font-mono">{{ $product->sku }}</span>@unless ($product->is_active) · {{ __('admin.products.status.draft') }}@endunless</span>
                                    </span>
                                    @if ($canSave)
                                        <button type="button" wire:click="removeProduct({{ $product->id }})" class="grid size-8 place-items-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-danger" aria-label="{{ __('admin.news.remove') }}: {{ $product->name }}"><x-admin.icon name="x" size="size-4" /></button>
                                    @endif
                                </li>
                            @empty
                                <li class="text-sm text-slate-500">{{ __('admin.news.no_products') }}</li>
                            @endforelse
                        </ul>
                        <x-admin.field-error name="form.product_ids" />
                        @if ($canSave)
                            <div class="relative mt-3">
                                <x-admin.icon name="search" size="size-4" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                                <input wire:model.live.debounce.300ms="productSearch" type="search" placeholder="{{ __('admin.news.product_search') }}" aria-label="{{ __('admin.news.product_search') }}" class="{{ $pickerInput }}">
                            </div>
                            @if (mb_strlen(trim($productSearch)) >= 2)
                                <ul class="mt-2 max-h-64 overflow-y-auto rounded-lg border border-slate-200" data-news-product-results>
                                    @forelse ($this->productResults as $result)
                                        <li wire:key="news-product-result-{{ $result->id }}">
                                            <button type="button" wire:click="addProduct({{ $result->id }})" class="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-slate-50">
                                                <span class="min-w-0 flex-1">
                                                    <span class="block truncate text-sm">{{ $result->name }}</span>
                                                    <span class="block truncate text-[11px] text-slate-400"><span class="font-mono">{{ $result->sku }}</span>@unless ($result->is_active) · {{ __('admin.products.status.draft') }}@endunless</span>
                                                </span>
                                                <x-admin.icon name="plus" size="size-4" class="text-slate-400" />
                                            </button>
                                        </li>
                                    @empty
                                        <li class="px-3 py-3 text-center text-sm text-slate-400">{{ __('admin.catalog.no_results') }}</li>
                                    @endforelse
                                </ul>
                            @endif
                        @endif
                    </div>

                    <div data-news-categories>
                        <h3 class="text-sm font-semibold">{{ __('admin.news.fields.categories') }}</h3>
                        <ul class="mt-2 space-y-2">
                            @forelse ($this->selectedCategories as $category)
                                <li wire:key="news-category-{{ $category['id'] }}" class="flex items-center gap-2 rounded-lg border border-slate-200 p-2" data-news-category="{{ $category['id'] }}">
                                    <span class="min-w-0 flex-1 truncate text-sm">{{ $category['path'] }}@unless ($category['active'])<span class="text-[11px] text-slate-400"> · {{ __('admin.news.hidden_category') }}</span>@endunless</span>
                                    @if ($canSave)
                                        <button type="button" wire:click="removeCategory({{ $category['id'] }})" class="grid size-8 place-items-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-danger" aria-label="{{ __('admin.news.remove') }}: {{ $category['path'] }}"><x-admin.icon name="x" size="size-4" /></button>
                                    @endif
                                </li>
                            @empty
                                <li class="text-sm text-slate-500">{{ __('admin.news.no_categories') }}</li>
                            @endforelse
                        </ul>
                        <x-admin.field-error name="form.category_ids" />
                        @if ($canSave)
                            <div class="relative mt-3">
                                <x-admin.icon name="search" size="size-4" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                                <input wire:model.live.debounce.300ms="categorySearch" type="search" placeholder="{{ __('admin.news.category_search') }}" aria-label="{{ __('admin.news.category_search') }}" class="{{ $pickerInput }}">
                            </div>
                            @if (mb_strlen(trim($categorySearch)) >= 2)
                                <ul class="mt-2 max-h-64 overflow-y-auto rounded-lg border border-slate-200" data-news-category-results>
                                    @forelse ($this->categoryResults as $result)
                                        <li wire:key="news-category-result-{{ $result['id'] }}">
                                            <button type="button" wire:click="addCategory({{ $result['id'] }})" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-50">
                                                <span class="min-w-0 flex-1 truncate">{{ $result['path'] }}</span>
                                                <x-admin.icon name="plus" size="size-4" class="text-slate-400" />
                                            </button>
                                        </li>
                                    @empty
                                        <li class="px-3 py-3 text-center text-sm text-slate-400">{{ __('admin.catalog.no_results') }}</li>
                                    @endforelse
                                </ul>
                            @endif
                        @endif
                    </div>
                </div>
            </x-admin.card>

            {{-- SEO --}}
            <x-admin.card :title="__('admin.catalog.seo')" :description="__('admin.news.seo_help')">
                <x-admin.locale-tabs :error-keys="['form.meta_title', 'form.meta_description']" :label="__('admin.catalog.seo')">
                    @foreach ($locales as $locale)
                        <x-admin.locale-panel :locale="$locale">
                            <x-admin.input wire:model="form.meta_title.{{ $locale }}" :label="__('admin.catalog.meta_title')" maxlength="255" :hint="__('admin.catalog.meta_title_hint')" />
                            <x-admin.textarea wire:model="form.meta_description.{{ $locale }}" :label="__('admin.catalog.meta_description')" rows="3" maxlength="500" :hint="__('admin.catalog.meta_description_hint')" />
                        </x-admin.locale-panel>
                    @endforeach
                </x-admin.locale-tabs>
            </x-admin.card>
        </div>

        {{-- Side column --}}
        <aside class="min-w-0 space-y-4 lg:sticky lg:top-24 lg:self-start lg:space-y-6">
            <x-admin.card :title="__('admin.news.publishing')">
                <div class="space-y-4">
                    @if ($newsPost)
                        <p class="flex flex-wrap items-center gap-1.5" data-news-state="{{ $newsPost->state()->value }}">@include('livewire.admin.news.partials.state', ['post' => $newsPost])</p>
                    @endif
                    <x-admin.select wire:model="form.status" :label="__('admin.news.fields.status')" :options="$statuses" />
                    <x-admin.input wire:model="form.published_at" type="datetime-local" :label="__('admin.news.fields.published_at')" :hint="__('admin.news.published_at_hint', ['timezone' => $timezone])" />
                    <p class="text-xs text-slate-500">{{ __('admin.news.locales_hint') }}</p>
                    @if ($publicUrl)
                        <a href="{{ $publicUrl }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 hover:underline" data-public-url>
                            <x-admin.icon name="external" size="size-4" />{{ $kind->adminText('view_on_site') }}
                        </a>
                    @endif
                </div>
            </x-admin.card>

            <x-admin.card :title="__('admin.news.fields.cover')" :description="$kind->adminText('cover_help')">
                <x-admin.image-upload model="form.image" :file="$form->image" :current-url="$currentImageUrl" :processing="$imageProcessing" remove-action="removeImage" alt-model="form.image_alt" />
            </x-admin.card>

            @if ($canDelete)
                <section class="rounded-2xl border border-red-200 bg-white p-4 sm:p-5">
                    <h2 class="text-sm font-semibold text-danger">{{ __('admin.catalog.danger_zone') }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ $kind->adminText('delete_help') }}</p>
                    <button
                        type="button"
                        x-on:click="$dispatch('confirm', {
                            title: @js($kind->adminText('delete_title')),
                            body: @js($kind->adminText('delete_body', ['name' => (string) $newsPost->title])),
                            label: @js(__('admin.common.delete')),
                            component: $wire.$id, method: 'delete',
                        })"
                        class="mt-3 h-10 w-full rounded-lg border border-red-200 text-sm font-semibold text-danger hover:bg-red-50"
                        data-delete-news
                    >{{ __('admin.common.delete') }}</button>
                </section>
            @endif
        </aside>
    </div>

    @if ($canSave)
        <x-admin.sticky-save-bar :cancel-href="route($kind->adminRoute('index'))" />
    @endif
</form>
