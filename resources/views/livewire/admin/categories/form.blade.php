@php
    $locales = config('app.supported_locales');
    $typeLabels = collect(\App\Enums\AttributeType::cases())->mapWithKeys(fn ($type) => [$type->value => $type->label()]);
@endphp

<form wire:submit="save" novalidate>
    <x-admin.page-header :title="$title" :back-href="route('admin.categories.index')" :back-label="__('admin.categories.title')">
        @if ($path)
            <x-slot:breadcrumbs>{{ $path }}</x-slot:breadcrumbs>
        @endif
        @if ($canSave)
            <x-slot:actions>
                <div class="hidden gap-2 lg:flex">
                    <x-admin.button variant="secondary" :href="route('admin.categories.index')" wire:navigate>{{ __('admin.common.cancel') }}</x-admin.button>
                    <x-admin.button type="submit" icon="check" loading="save">{{ __('admin.common.save') }}</x-admin.button>
                </div>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    @if ($category)
        <p class="mt-1 text-sm text-slate-500">{{ trans_choice('admin.categories.products_count', $category->productsCount(), ['count' => $category->productsCount()]) }}</p>
    @endif

    <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-3 lg:gap-6">
        <div class="min-w-0 space-y-4 lg:col-span-2 lg:space-y-6">

            {{-- Basic info --}}
            <x-admin.card :title="__('admin.catalog.basic_info')">
                <x-admin.locale-tabs :error-keys="['form.name', 'form.description']" :label="__('admin.form.content_language')">
                    @foreach ($locales as $locale)
                        <x-admin.locale-panel :locale="$locale">
                            <x-admin.input wire:model.blur="form.name.{{ $locale }}" :label="__('admin.catalog.name')" :required="$locale === 'uz'" maxlength="255">
                                <x-slot:labelAside><span class="ml-auto"><x-admin.ai-button field="category.name" target="form.name" /></span></x-slot:labelAside>
                            </x-admin.input>
                            <x-admin.rich-text wire:model="form.description.{{ $locale }}" :label="__('admin.catalog.description')">
                                <x-slot:labelAside><span class="ml-auto"><x-admin.ai-button field="category.description" target="form.description" /></span></x-slot:labelAside>
                            </x-admin.rich-text>
                        </x-admin.locale-panel>
                    @endforeach
                </x-admin.locale-tabs>

                <div class="mt-5" x-data="{ original: @js($category?->slug), active: @js((bool) $category?->is_active) }">
                    <label for="f-form-slug" class="text-sm font-semibold">{{ __('admin.catalog.slug') }}</label>
                    <div @class([
                        'mt-1.5 flex h-11 overflow-hidden rounded-lg border focus-within:ring-4',
                        'border-slate-200 focus-within:border-brand-500 focus-within:ring-brand-500/15' => ! $errors->has('form.slug'),
                        'border-red-300 focus-within:border-danger focus-within:ring-red-500/15' => $errors->has('form.slug'),
                    ])>
                        <span class="hidden items-center border-r border-slate-200 bg-slate-50 px-3 text-sm text-slate-500 sm:flex">/catalog/</span>
                        <input id="f-form-slug" wire:model="form.slug" placeholder="{{ $form->autoSlug() ?: __('admin.catalog.slug_auto') }}" autocomplete="off" spellcheck="false" aria-describedby="f-form-slug-hint" class="min-w-0 flex-1 px-3 font-mono text-base outline-none sm:text-sm">
                    </div>
                    <p id="f-form-slug-hint" class="mt-1 text-xs text-slate-500">{{ __('admin.catalog.slug_hint') }}</p>
                    <p x-cloak x-show="original && active && $wire.form.slug !== original" class="mt-1.5 flex items-start gap-1.5 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800" role="status">
                        <x-admin.icon name="alert" size="size-4" class="shrink-0" />{{ __('admin.catalog.slug_changed_warning') }}
                    </p>
                    <x-admin.field-error name="form.slug" />
                </div>
            </x-admin.card>

            {{-- Attributes --}}
            <section class="rounded-2xl border border-slate-200 bg-white" data-section="attributes">
                <div class="border-b border-slate-100 px-4 py-3.5 sm:px-6">
                    <h2 class="text-base font-semibold">{{ __('admin.categories.attributes.title') }}</h2>
                    <p class="mt-0.5 text-xs text-slate-500">{{ __('admin.categories.attributes.help') }}</p>
                </div>

                <div class="hidden grid-cols-[2rem_1fr_6.5rem_6.5rem_5.5rem] items-center gap-3 bg-slate-50 px-6 py-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500 sm:grid">
                    <span></span><span>{{ __('admin.categories.attributes.attribute') }}</span><span class="text-center">{{ __('admin.categories.attributes.required') }}</span><span class="text-center">{{ __('admin.categories.attributes.filter') }}</span><span></span>
                </div>

                {{-- Inherited (read-only) --}}
                @foreach ($this->inheritedGroups as $group)
                    <p class="flex items-center gap-2 border-t border-slate-100 px-4 py-2 text-xs font-semibold text-slate-500 sm:px-6" wire:key="inherited-{{ $group['category_id'] }}">
                        <x-admin.icon name="folder" size="size-4" class="text-slate-400" />
                        {{ __('admin.categories.attributes.inherited_from', ['name' => $group['name']]) }} ·
                        <a href="{{ route('admin.categories.edit', $group['category_id']) }}" wire:navigate class="text-brand-700 hover:underline">{{ __('admin.categories.attributes.edit_there') }}</a>
                    </p>
                    <ul class="divide-y divide-slate-100 border-t border-slate-100">
                        @foreach ($group['attributes'] as $attribute)
                            <li wire:key="inherited-attr-{{ $attribute->id }}" class="grid grid-cols-[1fr_auto] items-center gap-3 px-4 py-2.5 text-slate-500 sm:grid-cols-[2rem_1fr_6.5rem_6.5rem_5.5rem] sm:px-6" data-inherited="{{ $attribute->code }}">
                                <span class="hidden text-slate-300 sm:block"><x-admin.icon name="lock" size="size-4" /></span>
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium text-slate-600">{{ $attribute->name }}</span>
                                    <span class="block text-[11px] text-slate-400"><span class="font-mono">{{ $attribute->code }}</span> · {{ $attribute->type->label() }}@if ($attribute->unitLabel()) ({{ $attribute->unitLabel() }})@endif</span>
                                </span>
                                <span class="flex gap-1.5 sm:contents">
                                    <span class="text-center"><span @class(['inline-flex rounded px-1.5 py-0.5 text-[11px] font-semibold', 'bg-slate-200 text-slate-700' => $attribute->pivot->is_required, 'text-slate-300' => ! $attribute->pivot->is_required])>{{ $attribute->pivot->is_required ? __('admin.categories.attributes.required') : '—' }}</span></span>
                                    <span class="text-center"><span @class(['inline-flex rounded px-1.5 py-0.5 text-[11px] font-semibold', 'bg-slate-200 text-slate-700' => $attribute->pivot->is_filterable, 'text-slate-300' => ! $attribute->pivot->is_filterable])>{{ $attribute->pivot->is_filterable ? __('admin.categories.attributes.filter') : '—' }}</span></span>
                                    <span class="hidden sm:block"></span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endforeach

                {{-- Own --}}
                <p class="flex items-center gap-2 border-t border-slate-200 bg-tint px-4 py-2 text-xs font-semibold text-brand-800 sm:px-6">
                    <x-admin.icon name="sliders" size="size-4" />{{ __('admin.categories.attributes.own') }}
                </p>
                @if ($form->specs === [])
                    <p class="border-t border-slate-100 px-4 py-6 text-center text-sm text-slate-500 sm:px-6">{{ __('admin.categories.attributes.none') }}</p>
                @else
                    <ul wire:sort="sortAttribute" class="divide-y divide-slate-100 border-t border-slate-100" data-own-attributes>
                        @foreach ($form->specs as $index => $row)
                            @php($attribute = $this->specAttributes->get((int) $row['attribute_id']))
                            @continue($attribute === null)
                            @php($canFilter = $attribute->type->canBeFilterable())
                            <li wire:key="spec-{{ $attribute->id }}" wire:sort:item="{{ $attribute->id }}" class="grid grid-cols-[auto_1fr_auto] items-center gap-3 px-4 py-3 sm:grid-cols-[2rem_1fr_6.5rem_6.5rem_5.5rem] sm:px-6" data-own="{{ $attribute->code }}">
                                <span class="flex flex-col sm:block">
                                    <span wire:sort:handle class="hidden cursor-grab text-slate-400 sm:block" title="{{ __('admin.categories.drag') }}" aria-hidden="true"><x-admin.icon name="grip" /></span>
                                    <button type="button" wire:click="moveAttribute({{ $attribute->id }}, -1)" @disabled($loop->first) class="grid size-6 place-items-center rounded text-slate-400 disabled:opacity-30 sm:hidden" aria-label="{{ __('admin.common.move_up') }}: {{ $attribute->name }}"><x-admin.icon name="chevron-down" size="size-4" class="rotate-180" /></button>
                                    <button type="button" wire:click="moveAttribute({{ $attribute->id }}, 1)" @disabled($loop->last) class="grid size-6 place-items-center rounded text-slate-400 disabled:opacity-30 sm:hidden" aria-label="{{ __('admin.common.move_down') }}: {{ $attribute->name }}"><x-admin.icon name="chevron-down" size="size-4" /></button>
                                </span>
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-semibold">{{ $attribute->name }}</span>
                                    <span class="block text-[11px] text-slate-500"><span class="font-mono">{{ $attribute->code }}</span> · {{ $attribute->type->label() }}@if ($attribute->unitLabel()) ({{ $attribute->unitLabel() }})@endif</span>
                                    @if (in_array($attribute->id, $this->inheritedIds, true))
                                        <span class="mt-1 block text-[11px] font-medium text-amber-700">{{ __('admin.categories.attributes.already_inherited') }}</span>
                                    @endif
                                    <span class="mt-2 flex gap-2 sm:hidden">
                                        <label class="inline-flex h-8 cursor-pointer items-center rounded-md border border-slate-200 px-2.5 text-xs font-semibold text-slate-500 has-checked:border-brand-600 has-checked:bg-brand-50 has-checked:text-brand-700">
                                            <input type="checkbox" wire:model="form.specs.{{ $index }}.is_required" class="sr-only">{{ __('admin.categories.attributes.required') }}
                                        </label>
                                        @if ($canFilter)
                                            <label class="inline-flex h-8 cursor-pointer items-center rounded-md border border-slate-200 px-2.5 text-xs font-semibold text-slate-500 has-checked:border-brand-600 has-checked:bg-brand-50 has-checked:text-brand-700">
                                                <input type="checkbox" wire:model="form.specs.{{ $index }}.is_filterable" class="sr-only">{{ __('admin.categories.attributes.filter') }}
                                            </label>
                                        @endif
                                    </span>
                                </span>
                                <span class="hidden justify-center sm:flex">
                                    <label class="cursor-pointer"><span class="sr-only">{{ __('admin.categories.attributes.required') }}: {{ $attribute->name }}</span><input type="checkbox" role="switch" wire:model="form.specs.{{ $index }}.is_required" class="peer sr-only"><span class="relative block h-6 w-11 rounded-full bg-slate-200 transition after:absolute after:left-0.5 after:top-0.5 after:size-5 after:rounded-full after:bg-white after:shadow after:transition peer-checked:bg-brand-600 peer-checked:after:translate-x-5 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500 peer-focus-visible:ring-offset-2"></span></label>
                                </span>
                                <span class="hidden justify-center sm:flex">
                                    @if ($canFilter)
                                        <label class="cursor-pointer"><span class="sr-only">{{ __('admin.categories.attributes.filter') }}: {{ $attribute->name }}</span><input type="checkbox" role="switch" wire:model="form.specs.{{ $index }}.is_filterable" class="peer sr-only"><span class="relative block h-6 w-11 rounded-full bg-slate-200 transition after:absolute after:left-0.5 after:top-0.5 after:size-5 after:rounded-full after:bg-white after:shadow after:transition peer-checked:bg-brand-600 peer-checked:after:translate-x-5 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500 peer-focus-visible:ring-offset-2"></span></label>
                                    @else
                                        <span class="text-xs text-slate-300" title="{{ __('admin.attributes.text_not_filterable') }}">—</span>
                                    @endif
                                </span>
                                <span class="flex justify-end">
                                    <button type="button" wire:click="detachAttribute({{ $attribute->id }})" class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-danger" aria-label="{{ __('admin.categories.attributes.remove') }}: {{ $attribute->name }}"><x-admin.icon name="trash" size="size-4" /></button>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                {{-- Attach from the library --}}
                <div class="relative border-t border-slate-100 p-4 sm:px-6" x-data="{ picker: false }" x-on:click.outside="picker = false" x-on:keydown.escape="picker = false">
                    <button type="button" x-on:click="picker = !picker; $nextTick(() => picker && $refs.pq.focus())" x-bind:aria-expanded="picker.toString()" class="inline-flex h-10 items-center gap-2 rounded-lg border border-dashed border-slate-300 px-4 text-sm font-semibold text-slate-700 hover:border-brand-500 hover:bg-brand-50/40 hover:text-brand-700">
                        <x-admin.icon name="plus" size="size-4" />{{ __('admin.categories.attributes.attach') }}
                    </button>
                    <div x-cloak x-show="picker" x-transition.origin.top.left class="absolute inset-x-4 bottom-full z-30 mb-2 rounded-xl border border-slate-200 bg-white p-2 shadow-xl shadow-slate-900/10 sm:inset-x-auto sm:left-6 sm:w-[26rem]">
                        <div class="relative">
                            <x-admin.icon name="search" size="size-4" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                            <input x-ref="pq" wire:model.live.debounce.250ms="attributeSearch" type="search" placeholder="{{ __('admin.categories.attributes.search') }}" aria-label="{{ __('admin.categories.attributes.search') }}" class="h-10 w-full rounded-lg bg-slate-50 pl-9 pr-3 text-base outline-none focus:bg-white focus:ring-2 focus:ring-brand-500/30 sm:text-sm">
                        </div>
                        <ul class="mt-1 max-h-64 overflow-y-auto" data-picker-results>
                            @forelse ($this->attributeResults as $result)
                                <li wire:key="pick-{{ $result->id }}">
                                    <button type="button" wire:click="attachAttribute({{ $result->id }})" class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left hover:bg-slate-50">
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-sm font-medium">{{ $result->name }}</span>
                                            <span class="block truncate text-xs text-slate-400"><span class="font-mono">{{ $result->code }}</span> · {{ $result->type->label() }} · {{ trans_choice('admin.attributes.in_categories', $result->categories_count, ['count' => $result->categories_count]) }}</span>
                                        </span>
                                        <x-admin.icon name="plus" size="size-4" class="text-slate-400" />
                                    </button>
                                </li>
                            @empty
                                <li class="px-3 py-4 text-center text-sm text-slate-400">{{ __('admin.catalog.no_results') }}</li>
                            @endforelse
                        </ul>
                        @can('create', \App\Models\Attribute::class)
                            <a href="{{ route('admin.attributes.create') }}" wire:navigate class="mt-1 flex items-center gap-2 border-t border-slate-100 px-3 pb-1 pt-2.5 text-sm font-semibold text-brand-700"><x-admin.icon name="plus" size="size-4" />{{ __('admin.attributes.create') }}</a>
                        @endcan
                    </div>
                </div>
                <x-admin.field-error name="form.specs" class="px-4 pb-3 sm:px-6" />
            </section>

            {{-- SEO --}}
            <x-admin.card :title="__('admin.catalog.seo')" :description="__('admin.catalog.seo_help')">
                <x-admin.locale-tabs :error-keys="['form.meta_title', 'form.meta_description']" :label="__('admin.catalog.seo')">
                    @foreach ($locales as $locale)
                        <x-admin.locale-panel :locale="$locale">
                            <x-admin.input wire:model="form.meta_title.{{ $locale }}" :label="__('admin.catalog.meta_title')" maxlength="255" :hint="__('admin.catalog.meta_title_hint')">
                                <x-slot:labelAside><span class="ml-auto"><x-admin.ai-button field="category.meta_title" target="form.meta_title" /></span></x-slot:labelAside>
                            </x-admin.input>
                            <x-admin.textarea wire:model="form.meta_description.{{ $locale }}" :label="__('admin.catalog.meta_description')" rows="3" maxlength="500" :hint="__('admin.catalog.meta_description_hint')">
                                <x-slot:labelAside><span class="ml-auto"><x-admin.ai-button field="category.meta_description" target="form.meta_description" /></span></x-slot:labelAside>
                            </x-admin.textarea>
                        </x-admin.locale-panel>
                    @endforeach
                </x-admin.locale-tabs>
            </x-admin.card>

            {{-- FAQ --}}
            <section class="rounded-2xl border border-slate-200 bg-white">
                @if ($category)
                    <livewire:admin.faq.faq-items :category="$category" :key="'faq-category-'.$category->id" />
                @else
                    <div class="border-b border-slate-100 px-4 py-3.5 sm:px-6"><h2 class="text-base font-semibold">{{ __('admin.faq.title') }}</h2></div>
                    <p class="p-4 text-sm text-slate-500 sm:p-6">{{ __('admin.faq.save_first') }}</p>
                @endif
            </section>
        </div>

        {{-- Side column --}}
        <aside class="min-w-0 space-y-4 lg:sticky lg:top-24 lg:self-start lg:space-y-6">
            <x-admin.card :title="__('admin.categories.placement')">
                <div class="space-y-4">
                    <div>
                        <x-admin.select wire:model.live="form.parent_id" :label="__('admin.categories.fields.parent')" :placeholder="__('admin.categories.no_parent')" :options="$this->parentOptions" />
                        <p class="mt-1 text-xs text-slate-500">{{ __('admin.categories.parent_hint', ['max' => \App\Support\Catalog\CategoryTree::MAX_DEPTH]) }}</p>
                    </div>
                    <x-admin.select wire:model="form.icon" :label="__('admin.categories.fields.icon')" :placeholder="__('admin.categories.no_icon')" :options="collect(\App\Models\Category::ICONS)->mapWithKeys(fn ($icon) => [$icon => __('admin.categories.icons.'.$icon)])->all()" />
                    <x-admin.input wire:model="form.sort" type="number" min="0" inputmode="numeric" :label="__('admin.catalog.sort')" :hint="__('admin.catalog.sort_hint')" />
                    <x-admin.toggle wire:model="form.is_active" :label="__('admin.catalog.is_active')" :description="__('admin.categories.is_active_hint')" />
                </div>
            </x-admin.card>

            <x-admin.card :title="__('admin.categories.fields.image')">
                <x-admin.image-upload model="form.image" :file="$form->image" :current-url="$currentImageUrl" :processing="$imageProcessing" remove-action="removeImage" alt-model="form.image_alt" />
            </x-admin.card>

            <x-admin.card :title="__('admin.categories.attributes.preview_title')" :description="__('admin.categories.attributes.preview_help')">
                @php($previewRows = $this->productFormPreview)
                @if ($previewRows === [])
                    <p class="text-sm text-slate-500">{{ __('admin.categories.attributes.none') }}</p>
                @else
                    <ol class="space-y-1.5 text-sm">
                        @foreach ($previewRows as $row)
                            <li class="flex items-center gap-2">
                                <span class="w-5 text-right text-xs tabular-nums text-slate-400">{{ $loop->iteration }}</span>
                                <span class="truncate">{{ $row['name'] }}</span>
                                @if ($row['required'])<span class="text-danger" title="{{ __('admin.categories.attributes.required') }}">*</span>@endif
                                @if ($row['filter'])<span class="ml-auto shrink-0 rounded bg-brand-50 px-1.5 text-[10px] font-semibold text-brand-700">{{ __('admin.categories.attributes.filter') }}</span>@endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-admin.card>

            @if ($canDelete)
                <section class="rounded-2xl border border-red-200 bg-white p-4 sm:p-5">
                    <h2 class="text-sm font-semibold text-danger">{{ __('admin.catalog.danger_zone') }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ __('admin.categories.delete_help') }}</p>
                    <button
                        type="button"
                        x-on:click="$dispatch('confirm', {
                            title: @js(__('admin.categories.delete_title')),
                            body: @js(__('admin.categories.delete_body', ['name' => $category->name])),
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
        <x-admin.sticky-save-bar :cancel-href="route('admin.categories.index')" />
    @endif
</form>
