@php($locales = config('app.supported_locales'))

<form wire:submit="save" novalidate>
    <x-admin.page-header :title="$title" :back-href="route('admin.attributes.index')" :back-label="__('admin.attributes.title')">
        @if ($canSave)
            <x-slot:actions>
                <div class="hidden gap-2 lg:flex">
                    <x-admin.button variant="secondary" :href="route('admin.attributes.index')" wire:navigate>{{ __('admin.common.cancel') }}</x-admin.button>
                    <x-admin.button type="submit" icon="check" loading="save">{{ __('admin.common.save') }}</x-admin.button>
                </div>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-3 lg:gap-6">
        <div class="min-w-0 space-y-4 lg:col-span-2 lg:space-y-6">
            <x-admin.card :title="__('admin.catalog.basic_info')">
                <div class="space-y-5">
                    <x-admin.locale-tabs :error-keys="['form.name']">
                        @foreach ($locales as $locale)
                            <x-admin.locale-panel :locale="$locale">
                                <x-admin.input wire:model="form.name.{{ $locale }}" :label="__('admin.catalog.name')" :required="$locale === 'uz'" maxlength="255">
                                    <x-slot:labelAside><span class="ml-auto"><x-admin.ai-button :field="'form.name.'.$locale" /></span></x-slot:labelAside>
                                </x-admin.input>
                            </x-admin.locale-panel>
                        @endforeach
                    </x-admin.locale-tabs>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-admin.input wire:model="form.code" :label="__('admin.attributes.fields.code')" required maxlength="64" autocomplete="off" spellcheck="false" class="font-mono" :hint="__('admin.attributes.code_hint')" />
                        <x-admin.input wire:model="form.unit" :label="__('admin.attributes.fields.unit')" maxlength="16" :hint="__('admin.attributes.unit_hint')" />
                    </div>
                </div>
            </x-admin.card>

            @if ($type->hasOptions())
                <section class="rounded-2xl border border-slate-200 bg-white" data-section="options">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3.5 sm:px-6">
                        <div class="min-w-0">
                            <h2 class="text-base font-semibold">{{ __('admin.attributes.options.title') }}</h2>
                            <p class="mt-0.5 text-xs text-slate-500">{{ __('admin.attributes.options.help') }}</p>
                        </div>
                        <x-admin.button variant="soft" size="sm" icon="plus" wire:click="addOption">{{ __('admin.attributes.options.add') }}</x-admin.button>
                    </div>

                    <div class="hidden grid-cols-[2.5rem_repeat(3,minmax(0,1fr))_2.5rem] gap-3 bg-slate-50 px-6 py-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500 sm:grid">
                        <span></span>
                        @foreach ($locales as $locale)<span>{{ __('admin.attributes.options.value') }} · {{ strtoupper($locale) }}</span>@endforeach
                        <span></span>
                    </div>

                    @if ($form->options === [])
                        <p class="px-4 py-6 text-center text-sm text-slate-500 sm:px-6">{{ __('admin.attributes.options.none') }}</p>
                    @else
                        <ul class="divide-y divide-slate-100">
                            @foreach ($form->options as $index => $option)
                                <li wire:key="option-{{ $option['id'] ?? 'new-'.$index }}" class="grid grid-cols-[2.5rem_minmax(0,1fr)_2.5rem] items-start gap-3 px-4 py-3 sm:grid-cols-[2.5rem_repeat(3,minmax(0,1fr))_2.5rem] sm:px-6">
                                    <span class="flex flex-col items-center pt-1">
                                        <button type="button" wire:click="moveOption({{ $index }}, -1)" @disabled($loop->first) class="grid size-6 place-items-center rounded text-slate-400 hover:text-slate-700 disabled:opacity-30" aria-label="{{ __('admin.common.move_up') }}"><x-admin.icon name="chevron-down" size="size-4" class="rotate-180" /></button>
                                        <button type="button" wire:click="moveOption({{ $index }}, 1)" @disabled($loop->last) class="grid size-6 place-items-center rounded text-slate-400 hover:text-slate-700 disabled:opacity-30" aria-label="{{ __('admin.common.move_down') }}"><x-admin.icon name="chevron-down" size="size-4" /></button>
                                    </span>
                                    <div class="space-y-2 sm:contents">
                                        @foreach ($locales as $locale)
                                            <div class="flex items-start gap-2 sm:block">
                                                <span class="mt-3 w-6 shrink-0 text-[11px] font-bold uppercase text-slate-400 sm:hidden" aria-hidden="true">{{ $locale }}</span>
                                                <x-admin.input
                                                    wire:model="form.options.{{ $index }}.value.{{ $locale }}"
                                                    lang="{{ $locale }}"
                                                    maxlength="255"
                                                    class="flex-1"
                                                    :required="$locale === 'uz'"
                                                    aria-label="{{ __('admin.attributes.options.value') }} {{ $loop->parent->iteration }} ({{ strtoupper($locale) }})"
                                                />
                                            </div>
                                        @endforeach
                                    </div>
                                    <span class="flex justify-end pt-1">
                                        <button type="button" wire:click="removeOption({{ $index }})" class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-danger" aria-label="{{ __('admin.attributes.options.remove') }} {{ $loop->iteration }}"><x-admin.icon name="trash" size="size-4" /></button>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <x-admin.field-error name="form.options" class="px-4 pb-3 sm:px-6" />
                </section>
            @endif
        </div>

        <aside class="min-w-0 space-y-4 lg:sticky lg:top-24 lg:self-start lg:space-y-6">
            <x-admin.card :title="__('admin.attributes.settings')">
                <div class="space-y-4">
                    <div>
                        <x-admin.select wire:model.live="form.type" :label="__('admin.attributes.fields.type')" required :disabled="$typeLocked">
                            @foreach ($types as $typeCase)
                                <option value="{{ $typeCase->value }}">{{ $typeCase->label() }}</option>
                            @endforeach
                        </x-admin.select>
                        <p class="mt-1 text-xs text-slate-500">{{ $typeLocked ? __('admin.attributes.type_locked') : __('admin.attributes.type_hint') }}</p>
                    </div>
                    <x-admin.toggle wire:model="form.is_filterable" :label="__('admin.attributes.fields.is_filterable')" :description="$type->canBeFilterable() ? __('admin.attributes.filterable_hint') : __('admin.attributes.text_not_filterable')" :disabled="! $type->canBeFilterable()" />
                    <x-admin.input wire:model="form.sort" type="number" min="0" inputmode="numeric" :label="__('admin.catalog.sort')" :hint="__('admin.catalog.sort_hint')" />
                </div>
            </x-admin.card>

            @if ($attribute)
                <x-admin.card :title="__('admin.attributes.usage')">
                    <p class="text-sm text-slate-600">{{ trans_choice('admin.attributes.in_categories', $categoriesCount, ['count' => $categoriesCount]) }}</p>
                </x-admin.card>
            @endif

            @if ($canDelete)
                <section class="rounded-2xl border border-red-200 bg-white p-4 sm:p-5">
                    <h2 class="text-sm font-semibold text-danger">{{ __('admin.catalog.danger_zone') }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ __('admin.attributes.delete_help') }}</p>
                    <button
                        type="button"
                        x-on:click="$dispatch('confirm', {
                            title: @js(__('admin.attributes.delete_title')),
                            body: @js(trans_choice('admin.attributes.delete_body', $categoriesCount, ['name' => $attribute->name, 'count' => $categoriesCount])),
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
        <x-admin.sticky-save-bar :cancel-href="route('admin.attributes.index')" />
    @endif
</form>
