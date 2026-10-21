{{--
    Specs editor of the product form: rows from the category chain (with the
    category they come from), then extras. Skip / restore optional rows,
    ★ key specs (max 4), searchable option picker with inline "new value".
--}}
@php
    $groups = [
        'category' => __('admin.products.specs.from_category'),
        'extra' => __('admin.products.specs.extras'),
    ];
    $maxKeys = \App\Models\Product::MAX_KEY_SPECS;
@endphp

<section id="s-specs" class="scroll-mt-32 rounded-2xl border border-slate-200 bg-white" data-section="specs">
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-4 py-3.5 sm:px-6">
        <div class="min-w-0">
            <h2 class="text-base font-semibold">{{ __('admin.products.sections.specs') }}</h2>
            <p class="mt-0.5 text-xs text-slate-500">
                @if ($categoryName)
                    {{ __('admin.products.specs.from', ['name' => $categoryName]) }} ·
                @endif
                <x-admin.icon name="star" size="size-3.5" class="inline align-[-2px] text-warn" style="fill: currentColor" />
                {{ __('admin.products.specs.key_count', ['count' => $keyCount, 'max' => $maxKeys]) }}
            </p>
        </div>
        @if ($form->specs !== [])
            @if ($missingCount > 0)
                <span class="inline-flex items-center gap-1 rounded-md bg-red-50 px-2 py-1 text-xs font-semibold text-danger" data-missing="{{ $missingCount }}"><x-admin.icon name="alert" size="size-3.5" />{{ trans_choice('admin.products.specs.missing', $missingCount, ['count' => $missingCount]) }}</span>
            @else
                <span class="inline-flex items-center gap-1 rounded-md bg-brand-50 px-2 py-1 text-xs font-semibold text-brand-700"><x-admin.icon name="check" size="size-3.5" />{{ __('admin.products.specs.complete') }}</span>
            @endif
        @endif
    </div>

    @if ($movedToExtras !== [])
        <div class="flex gap-3 border-b border-amber-100 bg-amber-50 px-4 py-3 text-sm text-amber-900 sm:px-6" role="status" data-moved-to-extras>
            <x-admin.icon name="info" class="mt-0.5 shrink-0 text-warn" />
            <p>{{ __('admin.products.specs.moved_to_extras', ['list' => implode(', ', $movedToExtras)]) }}</p>
        </div>
    @endif

    @if (! $categoryChosen)
        <p class="px-4 py-6 text-center text-sm text-slate-500 sm:px-6">{{ __('admin.products.specs.choose_category') }}</p>
    @elseif ($form->specs === [])
        <p class="px-4 py-6 text-center text-sm text-slate-500 sm:px-6">{{ __('admin.products.specs.none') }}</p>
    @endif

    @foreach ($groups as $group => $groupTitle)
        @php
            $rows = collect($form->specs)->filter(function ($row) use ($group) {
                $meta = $this->specMeta[(int) $row['attribute_id']] ?? null;

                return $meta !== null && ($group === 'extra') === $meta->isExtra();
            });
        @endphp
        @continue($rows->isEmpty())

        <p class="bg-slate-50 px-4 py-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500 sm:px-6">{{ $groupTitle }}</p>
        <ul class="divide-y divide-slate-100" data-spec-group="{{ $group }}">
            @foreach ($rows as $index => $row)
                @php
                    $meta = $this->specMeta[(int) $row['attribute_id']];
                    $attribute = $meta->attribute;
                    $type = $attribute->type;
                    $skipped = (bool) $row['skipped'];
                    $empty = ! \App\Livewire\Forms\Admin\Products\ProductForm::rowHasValue($row, $type);
                    $flagged = $meta->required && $empty && ! $skipped;
                    $hasError = $errors->has("form.specs.{$index}") || $errors->has("form.specs.{$index}.*");
                    $unit = $attribute->unitLabel();
                @endphp
                <li wire:key="spec-{{ $attribute->id }}" @class(['grid gap-2 px-4 py-3 sm:grid-cols-[14rem_1fr_auto] sm:items-center sm:gap-4 sm:px-6', 'bg-slate-50/60' => $skipped]) data-spec="{{ $attribute->code }}">
                    {{-- Label --}}
                    <div class="flex min-w-0 items-start justify-between gap-2 sm:block">
                        <div class="min-w-0">
                            <p @class(['text-sm font-medium', 'text-slate-400 line-through' => $skipped, 'text-slate-800' => ! $skipped])>
                                {{ $attribute->name }}@if ($meta->required)<span class="text-danger" title="{{ __('admin.categories.attributes.required') }}"> *</span>@endif
                            </p>
                            <p class="mt-0.5 truncate text-[11px] text-slate-400">
                                {{ $type->label() }}@if ($unit) ({{ $unit }})@endif
                                @if ($meta->isExtra())
                                    · <span class="font-semibold text-violet-600">{{ __('admin.products.specs.extra') }}</span>
                                @else
                                    · {{ \App\Support\Catalog\CategoryTree::name($meta->categoryId) }}
                                @endif
                            </p>
                        </div>
                        <div class="flex shrink-0 gap-1 sm:hidden">
                            @include('livewire.admin.products.partials.spec-actions', ['compact' => true])
                        </div>
                    </div>

                    {{-- Value --}}
                    <div class="min-w-0">
                        @if ($skipped)
                            <p class="text-sm italic text-slate-400">{{ __('admin.products.specs.skipped') }}</p>
                        @else
                            @switch($type)
                                @case(\App\Enums\AttributeType::Text)
                                    <div class="grid gap-2 sm:grid-cols-3">
                                        @foreach ($locales as $locale)
                                            <div @class(['flex h-10 overflow-hidden rounded-lg border focus-within:border-brand-500 focus-within:ring-4 focus-within:ring-brand-500/15', 'border-red-300 bg-red-50/40' => $flagged || $errors->has("form.specs.{$index}.text.{$locale}"), 'border-slate-200' => ! ($flagged || $errors->has("form.specs.{$index}.text.{$locale}"))])>
                                                <span class="flex w-9 shrink-0 items-center justify-center bg-slate-50 text-[11px] font-bold uppercase text-slate-400" aria-hidden="true">{{ $locale }}</span>
                                                <input wire:model="form.specs.{{ $index }}.text.{{ $locale }}" lang="{{ $locale }}" maxlength="1000" aria-label="{{ $attribute->name }} ({{ strtoupper($locale) }})" class="min-w-0 flex-1 bg-transparent px-2.5 text-base outline-none sm:text-sm">
                                            </div>
                                        @endforeach
                                    </div>
                                    @break

                                @case(\App\Enums\AttributeType::Number)
                                    <div @class(['flex h-10 overflow-hidden rounded-lg border focus-within:border-brand-500 focus-within:ring-4 focus-within:ring-brand-500/15 sm:max-w-xs', 'border-red-300 bg-red-50/40' => $flagged || $hasError, 'border-slate-200' => ! ($flagged || $hasError)])>
                                        <input wire:model="form.specs.{{ $index }}.number" inputmode="decimal" placeholder="0" aria-label="{{ $attribute->name }}" class="min-w-0 flex-1 bg-transparent px-3 text-base tabular-nums outline-none sm:text-sm">
                                        @if ($unit)<span class="flex items-center bg-slate-50 px-3 text-sm text-slate-500">{{ $unit }}</span>@endif
                                    </div>
                                    @break

                                @case(\App\Enums\AttributeType::Boolean)
                                    <div @class(['flex rounded-lg p-1 text-sm font-semibold sm:w-56', 'bg-red-50 ring-1 ring-red-300' => $flagged, 'bg-slate-100' => ! $flagged]) role="radiogroup" aria-label="{{ $attribute->name }}">
                                        @foreach (['1' => __('admin.common.yes'), '0' => __('admin.common.no'), '' => __('admin.common.empty_value')] as $boolValue => $boolLabel)
                                            <label class="flex-1 cursor-pointer">
                                                <input type="radio" wire:model.live="form.specs.{{ $index }}.bool" value="{{ $boolValue }}" class="peer sr-only">
                                                <span class="flex h-8 items-center justify-center rounded-md text-slate-500 peer-checked:bg-white peer-checked:text-slate-900 peer-checked:shadow-sm peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500">{{ $boolLabel }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    @break

                                @default
                                    @include('livewire.admin.products.partials.spec-options')
                            @endswitch
                        @endif
                        <x-admin.field-error name="form.specs.{{ $index }}" />
                        <x-admin.field-error name="form.specs.{{ $index }}.number" />
                        <x-admin.field-error name="form.specs.{{ $index }}.text.uz" />
                    </div>

                    {{-- Desktop actions --}}
                    <div class="hidden items-center gap-1 sm:flex">
                        @include('livewire.admin.products.partials.spec-actions', ['compact' => false])
                    </div>
                </li>
            @endforeach
        </ul>
    @endforeach

    {{-- Add an extra attribute from the library --}}
    @if ($categoryChosen)
        <div class="relative border-t border-slate-100 p-4 sm:px-6" x-data="{ picker: false }" x-on:click.outside="picker = false" x-on:keydown.escape="picker = false">
            <button type="button" x-on:click="picker = !picker; $nextTick(() => picker && $refs.extraSearch.focus())" x-bind:aria-expanded="picker.toString()" class="inline-flex h-10 items-center gap-2 rounded-lg border border-dashed border-slate-300 px-4 text-sm font-semibold text-slate-700 hover:border-brand-500 hover:bg-brand-50/40 hover:text-brand-700">
                <x-admin.icon name="plus" size="size-4" />{{ __('admin.products.specs.add_extra') }}
            </button>
            <p class="mt-2 text-xs text-slate-500">{{ __('admin.products.specs.extra_help') }}</p>
            <div x-cloak x-show="picker" x-transition.origin.top.left class="absolute inset-x-4 bottom-full z-30 mb-2 rounded-xl border border-slate-200 bg-white p-2 shadow-xl shadow-slate-900/10 sm:inset-x-auto sm:left-6 sm:w-[26rem]">
                <div class="relative">
                    <x-admin.icon name="search" size="size-4" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                    <input x-ref="extraSearch" wire:model.live.debounce.250ms="extraSearch" x-on:keydown.enter.prevent type="search" placeholder="{{ __('admin.categories.attributes.search') }}" aria-label="{{ __('admin.categories.attributes.search') }}" class="h-10 w-full rounded-lg bg-slate-50 pl-9 pr-3 text-base outline-none focus:bg-white focus:ring-2 focus:ring-brand-500/30 sm:text-sm">
                </div>
                <ul class="mt-1 max-h-64 overflow-y-auto" data-extra-results>
                    @forelse ($this->extraResults as $result)
                        <li wire:key="extra-{{ $result->id }}">
                            <button type="button" wire:click="addExtra({{ $result->id }})" x-on:click="picker = false" class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left hover:bg-slate-50">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium">{{ $result->name }}</span>
                                    <span class="block truncate text-xs text-slate-400"><span class="font-mono">{{ $result->code }}</span> · {{ $result->type->label() }}@if ($result->unitLabel()) ({{ $result->unitLabel() }})@endif</span>
                                </span>
                                <x-admin.icon name="plus" size="size-4" class="text-slate-400" />
                            </button>
                        </li>
                    @empty
                        <li class="px-3 py-4 text-center text-sm text-slate-400">{{ __('admin.catalog.no_results') }}</li>
                    @endforelse
                </ul>
                @if ($canCreateAttributes)
                    <a href="{{ route('admin.attributes.create') }}" wire:navigate class="mt-1 flex items-center gap-2 border-t border-slate-100 px-3 pb-1 pt-2.5 text-sm font-semibold text-brand-700"><x-admin.icon name="sliders" size="size-4" />{{ __('admin.products.specs.create_attribute') }}</a>
                @endif
            </div>
        </div>
    @endif
</section>
