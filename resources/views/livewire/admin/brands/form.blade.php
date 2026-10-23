@php($locales = config('app.supported_locales'))

<form wire:submit="save" novalidate>
    <x-admin.page-header :title="$title" :back-href="route('admin.brands.index')" :back-label="__('admin.brands.title')">
        @if ($canSave)
            <x-slot:actions>
                <div class="hidden gap-2 lg:flex">
                    <x-admin.button variant="secondary" :href="route('admin.brands.index')" wire:navigate>{{ __('admin.common.cancel') }}</x-admin.button>
                    <x-admin.button type="submit" icon="check" loading="save">{{ __('admin.common.save') }}</x-admin.button>
                </div>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-3 lg:gap-6">
        <div class="min-w-0 space-y-4 lg:col-span-2 lg:space-y-6">
            <x-admin.card :title="__('admin.catalog.basic_info')">
                <div class="space-y-5">
                    <x-admin.input wire:model.blur="form.name" :label="__('admin.catalog.name')" required maxlength="255" :hint="__('admin.brands.name_hint')" />

                    <div x-data="{ original: @js($brand?->slug), active: @js((bool) $brand?->is_active) }">
                        <label for="f-form-slug" class="text-sm font-semibold">{{ __('admin.catalog.slug') }}</label>
                        <div @class([
                            'mt-1.5 flex h-11 overflow-hidden rounded-lg border focus-within:ring-4',
                            'border-slate-200 focus-within:border-brand-500 focus-within:ring-brand-500/15' => ! $errors->has('form.slug'),
                            'border-red-300 focus-within:border-danger focus-within:ring-red-500/15' => $errors->has('form.slug'),
                        ])>
                            <span class="hidden items-center border-r border-slate-200 bg-slate-50 px-3 text-sm text-slate-500 sm:flex">/brands/</span>
                            <input id="f-form-slug" wire:model="form.slug" placeholder="{{ $autoSlug ?: __('admin.catalog.slug_auto') }}" autocomplete="off" spellcheck="false" aria-describedby="f-form-slug-hint" class="min-w-0 flex-1 px-3 font-mono text-base outline-none sm:text-sm">
                        </div>
                        <p id="f-form-slug-hint" class="mt-1 text-xs text-slate-500">{{ __('admin.brands.slug_hint') }}</p>
                        <p x-cloak x-show="original && active && $wire.form.slug !== original" class="mt-1.5 flex items-start gap-1.5 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800" role="status">
                            <x-admin.icon name="alert" size="size-4" class="shrink-0" />{{ __('admin.catalog.slug_changed_warning') }}
                        </p>
                        <x-admin.field-error name="form.slug" />
                    </div>

                    <x-admin.input wire:model="form.website" type="url" inputmode="url" :label="__('admin.brands.fields.website')" placeholder="https://" maxlength="2048" :hint="__('admin.brands.website_hint')" />

                    <x-admin.locale-tabs :error-keys="['form.description']" :label="__('admin.form.content_language')">
                        @foreach ($locales as $locale)
                            <x-admin.locale-panel :locale="$locale">
                                <x-admin.rich-text wire:model="form.description.{{ $locale }}" :label="__('admin.catalog.description')">
                                    <x-slot:labelAside><span class="ml-auto"><x-admin.ai-button field="brand.description" target="form.description" /></span></x-slot:labelAside>
                                </x-admin.rich-text>
                            </x-admin.locale-panel>
                        @endforeach
                    </x-admin.locale-tabs>
                </div>
            </x-admin.card>
        </div>

        <aside class="min-w-0 space-y-4 lg:sticky lg:top-24 lg:self-start lg:space-y-6">
            <x-admin.card :title="__('admin.brands.publish')">
                <div class="space-y-4">
                    <x-admin.toggle wire:model="form.is_active" :label="__('admin.brands.is_active')" :description="__('admin.brands.is_active_hint')" />
                    <x-admin.input wire:model="form.sort" type="number" min="0" inputmode="numeric" :label="__('admin.catalog.sort')" :hint="__('admin.catalog.sort_hint')" />
                </div>
            </x-admin.card>

            <x-admin.card :title="__('admin.brands.fields.logo')">
                <x-admin.image-upload model="form.logo" :file="$form->logo" :current-url="$currentLogoUrl" :processing="$logoProcessing" remove-action="removeLogo" alt-model="form.logo_alt" />
            </x-admin.card>

            @if ($canDelete)
                <section class="rounded-2xl border border-red-200 bg-white p-4 sm:p-5">
                    <h2 class="text-sm font-semibold text-danger">{{ __('admin.catalog.danger_zone') }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ __('admin.brands.delete_help') }}</p>
                    <button
                        type="button"
                        x-on:click="$dispatch('confirm', {
                            title: @js(__('admin.brands.delete_title')),
                            body: @js(__('admin.brands.delete_body', ['name' => $brand->name])),
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
        <x-admin.sticky-save-bar :cancel-href="route('admin.brands.index')" />
    @endif
</form>
