@php
    $locales = config('app.supported_locales');
    $isCategory = $form->placement === \App\Enums\BannerPlacement::Category->value;
@endphp

<form wire:submit="save" novalidate>
    <x-admin.page-header :title="$title" :back-href="route('admin.banners.index')" :back-label="__('admin.banners.title')">
        <x-slot:breadcrumbs>{{ __('menu.groups.content') }} <span class="text-slate-300">/</span> {{ __('admin.banners.title') }}</x-slot:breadcrumbs>
        @if ($canSave)
            <x-slot:actions>
                <div class="hidden gap-2 lg:flex">
                    <x-admin.button variant="secondary" :href="route('admin.banners.index')" wire:navigate>{{ __('admin.common.cancel') }}</x-admin.button>
                    <x-admin.button type="submit" icon="check" loading="save">{{ __('admin.common.save') }}</x-admin.button>
                </div>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-3 lg:gap-6">
        <div class="min-w-0 space-y-4 lg:col-span-2 lg:space-y-6">

            {{-- Texts --}}
            <x-admin.card :title="__('admin.banners.content')" :description="__('admin.banners.content_help')">
                <x-admin.locale-tabs :error-keys="['form.title', 'form.text', 'form.button_label']" :label="__('admin.form.content_language')">
                    @foreach ($locales as $locale)
                        <x-admin.locale-panel :locale="$locale">
                            <x-admin.input wire:model.blur="form.title.{{ $locale }}" :label="__('admin.banners.fields.title')" :required="$locale === 'uz'" maxlength="{{ \App\Models\Banner::TITLE_MAX }}" :hint="__('admin.banners.max_hint', ['max' => \App\Models\Banner::TITLE_MAX])">
                                <x-slot:labelAside><span class="ml-auto"><x-admin.ai-button field="banner.title" target="form.title" /></span></x-slot:labelAside>
                            </x-admin.input>
                            <x-admin.textarea wire:model="form.text.{{ $locale }}" :label="__('admin.banners.fields.text')" rows="3" maxlength="{{ \App\Models\Banner::TEXT_MAX }}" :hint="__('admin.banners.max_hint', ['max' => \App\Models\Banner::TEXT_MAX])">
                                <x-slot:labelAside><span class="ml-auto"><x-admin.ai-button field="banner.text" target="form.text" /></span></x-slot:labelAside>
                            </x-admin.textarea>
                            <x-admin.input wire:model="form.button_label.{{ $locale }}" :label="__('admin.banners.fields.button_label')" maxlength="{{ \App\Models\Banner::BUTTON_MAX }}" :hint="__('admin.banners.button_hint')" />
                        </x-admin.locale-panel>
                    @endforeach
                </x-admin.locale-tabs>

                <div class="mt-5">
                    <x-admin.input wire:model="form.link" :label="__('admin.banners.fields.link')" placeholder="/catalog/..." maxlength="2048" autocomplete="off" spellcheck="false" :hint="__('admin.banners.link_hint')" />
                </div>
            </x-admin.card>

            {{-- Images --}}
            <x-admin.card :title="__('admin.banners.images')" :description="__('admin.banners.images_help')">
                <p class="mb-5 flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2.5 text-sm text-amber-800" role="note" data-no-text-hint>
                    <x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __('admin.banners.no_text_in_images') }}
                </p>
                <div class="grid gap-6 md:grid-cols-2">
                    <div data-banner-desktop>
                        <x-admin.image-upload
                            :label="__('admin.banners.fields.desktop').' *'"
                            model="form.desktop"
                            :file="$form->desktop"
                            :current-url="$desktopUrl"
                            :processing="$desktopProcessing"
                            :remove-action="$form->desktop ? 'removeDesktop' : null"
                            alt-model="form.desktop_alt"
                            :hint="__('admin.banners.desktop_hint', ['width' => \App\Models\Banner::DESKTOP_WIDTH, 'height' => \App\Models\Banner::DESKTOP_HEIGHT])"
                        />
                    </div>
                    <div data-banner-mobile>
                        <x-admin.image-upload
                            :label="__('admin.banners.fields.mobile')"
                            model="form.mobile"
                            :file="$form->mobile"
                            :current-url="$mobileUrl"
                            :processing="$mobileProcessing"
                            remove-action="removeMobile"
                            alt-model="form.mobile_alt"
                            :hint="__('admin.banners.mobile_hint', ['width' => \App\Models\Banner::MOBILE_WIDTH, 'height' => \App\Models\Banner::MOBILE_HEIGHT])"
                        />
                    </div>
                </div>
            </x-admin.card>
        </div>

        {{-- Side column --}}
        <aside class="min-w-0 space-y-4 lg:sticky lg:top-24 lg:self-start lg:space-y-6">
            <x-admin.card :title="__('admin.banners.publishing')">
                <div class="space-y-4">
                    @if ($banner)
                        @php($state = $banner->state())
                        <p data-banner-state="{{ $state->value }}"><x-admin.badge :color="$state->color()" pill dot>{{ $state->label() }}</x-admin.badge></p>
                    @endif
                    <x-admin.select wire:model.live="form.placement" :label="__('admin.banners.fields.placement')" :options="$placements" required />
                    @if ($isCategory)
                        <x-admin.select wire:model="form.category_id" :label="__('admin.banners.fields.category')" :options="$this->categoryOptions" :placeholder="__('admin.banners.choose_category')" :hint="__('admin.banners.category_hint')" required data-banner-category />
                    @endif
                    <x-admin.input wire:model="form.starts_at" type="datetime-local" :label="__('admin.banners.fields.starts_at')" />
                    <x-admin.input wire:model="form.ends_at" type="datetime-local" :label="__('admin.banners.fields.ends_at')" :hint="__('admin.banners.dates_hint', ['timezone' => $timezone])" />
                    <x-admin.toggle wire:model="form.is_active" :label="__('admin.banners.fields.is_active')" :description="__('admin.banners.is_active_hint')" />
                </div>
            </x-admin.card>

            @if ($canDelete)
                <section class="rounded-2xl border border-red-200 bg-white p-4 sm:p-5">
                    <h2 class="text-sm font-semibold text-danger">{{ __('admin.catalog.danger_zone') }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ __('admin.banners.delete_help') }}</p>
                    <button
                        type="button"
                        x-on:click="$dispatch('confirm', {
                            title: @js(__('admin.banners.delete_title')),
                            body: @js(__('admin.banners.delete_body', ['name' => (string) $banner->title])),
                            label: @js(__('admin.common.delete')),
                            component: $wire.$id, method: 'delete',
                        })"
                        class="mt-3 h-10 w-full rounded-lg border border-red-200 text-sm font-semibold text-danger hover:bg-red-50"
                        data-delete-banner
                    >{{ __('admin.common.delete') }}</button>
                </section>
            @endif
        </aside>
    </div>

    @if ($canSave)
        <x-admin.sticky-save-bar :cancel-href="route('admin.banners.index')" />
    @endif
</form>
