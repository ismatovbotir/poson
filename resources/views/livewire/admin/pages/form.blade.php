@php
    $locales = config('app.supported_locales');
    $aiBody = $this->allowsAiBody();
@endphp

<form wire:submit="save" novalidate>
    <x-admin.page-header :title="$title" :back-href="route('admin.pages.index')" :back-label="__('admin.pages.title')">
        @if ($page)
            <x-slot:breadcrumbs>/page/{{ $page->slug }}</x-slot:breadcrumbs>
        @endif
        @if ($canSave)
            <x-slot:actions>
                <div class="hidden gap-2 lg:flex">
                    <x-admin.button variant="secondary" :href="route('admin.pages.index')" wire:navigate>{{ __('admin.common.cancel') }}</x-admin.button>
                    <x-admin.button type="submit" icon="check" loading="save">{{ __('admin.common.save') }}</x-admin.button>
                </div>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    @if ($page?->needs_review)
        <p class="mt-3 flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2.5 text-sm text-amber-800" role="status" data-needs-review>
            <x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />
            <span><strong class="font-semibold">{{ __('admin.pages.needs_review') }}.</strong> {{ __('admin.pages.needs_review_notice') }}</span>
        </p>
    @endif

    <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-3 lg:gap-6">
        <div class="min-w-0 space-y-4 lg:col-span-2 lg:space-y-6">

            {{-- Content --}}
            <x-admin.card :title="__('admin.pages.content')">
                <x-admin.locale-tabs :error-keys="['form.title', 'form.body']" :label="__('admin.form.content_language')">
                    @foreach ($locales as $locale)
                        <x-admin.locale-panel :locale="$locale">
                            <x-admin.input wire:model.blur="form.title.{{ $locale }}" :label="__('admin.pages.fields.title')" :required="$locale === 'uz'" maxlength="255">
                                <x-slot:labelAside><span class="ml-auto"><x-admin.ai-button field="page.title" target="form.title" /></span></x-slot:labelAside>
                            </x-admin.input>
                            <x-admin.rich-text wire:model="form.body.{{ $locale }}" :label="__('admin.pages.fields.body')" :hint="$aiBody ? null : __('admin.pages.legal_body_hint')">
                                @if ($aiBody)
                                    <x-slot:labelAside><span class="ml-auto"><x-admin.ai-button field="page.body" target="form.body" /></span></x-slot:labelAside>
                                @endif
                            </x-admin.rich-text>
                        </x-admin.locale-panel>
                    @endforeach
                </x-admin.locale-tabs>

                <x-admin.slug-field class="mt-5" prefix="/page/" :slug="$page?->slug" :preview="$form->autoSlug()" :hint="__('admin.pages.slug_hint')" :locked-hint="$isSystem ? __('admin.pages.system_slug_hint') : null" />
            </x-admin.card>

            {{-- SEO --}}
            <x-admin.card :title="__('admin.catalog.seo')" :description="__('admin.pages.seo_help')">
                <x-admin.locale-tabs :error-keys="['form.meta_title', 'form.meta_description']" :label="__('admin.catalog.seo')">
                    @foreach ($locales as $locale)
                        <x-admin.locale-panel :locale="$locale">
                            <x-admin.input wire:model="form.meta_title.{{ $locale }}" :label="__('admin.catalog.meta_title')" maxlength="255" :hint="__('admin.catalog.meta_title_hint')">
                                <x-slot:labelAside><span class="ml-auto"><x-admin.ai-button field="page.meta_title" target="form.meta_title" /></span></x-slot:labelAside>
                            </x-admin.input>
                            <x-admin.textarea wire:model="form.meta_description.{{ $locale }}" :label="__('admin.catalog.meta_description')" rows="3" maxlength="500" :hint="__('admin.catalog.meta_description_hint')">
                                <x-slot:labelAside><span class="ml-auto"><x-admin.ai-button field="page.meta_description" target="form.meta_description" /></span></x-slot:labelAside>
                            </x-admin.textarea>
                        </x-admin.locale-panel>
                    @endforeach
                </x-admin.locale-tabs>
            </x-admin.card>

            {{-- FAQ --}}
            <section class="rounded-2xl border border-slate-200 bg-white" data-section="faq">
                @if ($page)
                    <livewire:admin.faq.faq-items :page="$page" :key="'faq-page-'.$page->id" />
                @else
                    <div class="border-b border-slate-100 px-4 py-3.5 sm:px-6"><h2 class="text-base font-semibold">{{ __('admin.faq.title') }}</h2></div>
                    <p class="p-4 text-sm text-slate-500 sm:p-6">{{ __('admin.faq.save_first') }}</p>
                @endif
            </section>
        </div>

        {{-- Side column --}}
        <aside class="min-w-0 space-y-4 lg:sticky lg:top-24 lg:self-start lg:space-y-6">
            <x-admin.card :title="__('admin.pages.publishing')">
                <div class="space-y-4">
                    @if ($isSystem)
                        <div>
                            <span class="text-sm font-semibold">{{ __('admin.pages.fields.template') }}</span>
                            <p class="mt-1.5 flex items-center gap-2 text-sm text-slate-600" data-template-locked><x-admin.icon name="lock" size="size-4" class="text-slate-400" />{{ $template->label() }}</p>
                        </div>
                    @else
                        <x-admin.select wire:model.live="form.template" :label="__('admin.pages.fields.template')" :options="collect(\App\Enums\PageTemplate::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" />
                    @endif
                    <x-admin.input wire:model="form.sort" type="number" min="0" inputmode="numeric" :label="__('admin.catalog.sort')" :hint="__('admin.catalog.sort_hint')" />
                    <x-admin.toggle wire:model="form.is_active" :label="__('admin.pages.fields.is_active')" :description="__('admin.pages.is_active_hint')" />
                    <x-admin.toggle wire:model="form.needs_review" :label="__('admin.pages.needs_review')" :description="__('admin.pages.needs_review_hint')" />
                    @if ($publicUrl)
                        <a href="{{ $publicUrl }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 hover:underline" data-public-url>
                            <x-admin.icon name="external" size="size-4" />{{ __('admin.pages.view_on_site') }}
                        </a>
                    @endif
                </div>
            </x-admin.card>

            <x-admin.card :title="__('admin.pages.fields.cover')" :description="__('admin.pages.cover_help')">
                <x-admin.image-upload model="form.image" :file="$form->image" :current-url="$currentImageUrl" :processing="$imageProcessing" remove-action="removeImage" alt-model="form.image_alt" />
            </x-admin.card>

            @if ($canDelete)
                <section class="rounded-2xl border border-red-200 bg-white p-4 sm:p-5">
                    <h2 class="text-sm font-semibold text-danger">{{ __('admin.catalog.danger_zone') }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ __('admin.pages.delete_help') }}</p>
                    <button
                        type="button"
                        x-on:click="$dispatch('confirm', {
                            title: @js(__('admin.pages.delete_title')),
                            body: @js(__('admin.pages.delete_body', ['name' => (string) $page->title])),
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
        <x-admin.sticky-save-bar :cancel-href="route('admin.pages.index')" />
    @endif
</form>
