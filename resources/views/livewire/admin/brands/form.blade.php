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

                    <x-admin.slug-field prefix="/brands/" :slug="$brand?->slug" :preview="$autoSlug" :hint="__('admin.brands.slug_hint')" />

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

            {{-- SEO (plan 12): empty = the site's default brand title / description --}}
            <x-admin.card :title="__('admin.catalog.seo')" :description="__('admin.catalog.seo_help')" data-brand-seo>
                <x-admin.locale-tabs :error-keys="['form.meta_title', 'form.meta_description']" :label="__('admin.catalog.seo')">
                    @foreach ($locales as $locale)
                        <x-admin.locale-panel :locale="$locale">
                            <x-admin.input wire:model="form.meta_title.{{ $locale }}" :label="__('admin.catalog.meta_title')" maxlength="{{ \App\Models\Brand::META_TITLE_MAX }}" :hint="__('admin.catalog.meta_title_hint')">
                                <x-slot:labelAside>
                                    <span class="ml-auto flex items-center gap-2">
                                        <span class="text-xs font-medium tabular-nums text-slate-400" x-text="(($wire.form.meta_title[@js($locale)] ?? '').length) + '/{{ \App\Models\Brand::META_TITLE_MAX }}'"></span>
                                        <x-admin.ai-button field="brand.meta_title" target="form.meta_title" />
                                    </span>
                                </x-slot:labelAside>
                            </x-admin.input>
                            <x-admin.textarea wire:model="form.meta_description.{{ $locale }}" :label="__('admin.catalog.meta_description')" rows="3" maxlength="{{ \App\Models\Brand::META_DESCRIPTION_MAX }}" :hint="__('admin.catalog.meta_description_hint')">
                                <x-slot:labelAside>
                                    <span class="ml-auto flex items-center gap-2">
                                        <span class="text-xs font-medium tabular-nums text-slate-400" x-text="(($wire.form.meta_description[@js($locale)] ?? '').length) + '/{{ \App\Models\Brand::META_DESCRIPTION_MAX }}'"></span>
                                        <x-admin.ai-button field="brand.meta_description" target="form.meta_description" />
                                    </span>
                                </x-slot:labelAside>
                            </x-admin.textarea>
                        </x-admin.locale-panel>
                    @endforeach
                </x-admin.locale-tabs>
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
