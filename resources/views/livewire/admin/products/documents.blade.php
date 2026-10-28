{{-- Product documents (inside the product form: no <form>, Enter never submits the parent). --}}
<div x-on:keydown.enter="if ($event.target.tagName === 'INPUT') $event.preventDefault()" data-product-documents>
    <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3.5 sm:px-6">
        <div class="min-w-0">
            <h2 class="text-base font-semibold">{{ __('admin.products.sections.documents') }}</h2>
            <p class="mt-0.5 text-xs text-slate-500">{{ __('admin.products.documents.help') }}</p>
        </div>
        @if ($canEdit && ! $editing)
            <button type="button" wire:click="create" class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-lg bg-brand-50 px-3 text-sm font-semibold text-brand-700 hover:bg-brand-100"><x-admin.icon name="plus" size="size-4" />{{ __('admin.products.documents.add') }}</button>
        @endif
    </div>

    @if ($editing)
        <div class="space-y-4 border-b border-slate-100 bg-slate-50/60 p-4 sm:p-6" data-document-editor>
            <x-admin.locale-tabs :error-keys="['form.title']" :label="__('admin.products.documents.title_field')">
                @foreach (config('app.supported_locales') as $locale)
                    <x-admin.locale-panel :locale="$locale">
                        <x-admin.input wire:model="form.title.{{ $locale }}" :label="__('admin.products.documents.title_field')" :required="$locale === 'uz'" maxlength="255" :placeholder="__('admin.products.documents.title_placeholder', [], $locale)" :hint="__('admin.products.documents.title_hint')" />
                    </x-admin.locale-panel>
                @endforeach
            </x-admin.locale-tabs>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-admin.select wire:model="form.type" :label="__('admin.products.documents.type')" :options="collect(\App\Enums\DocumentType::cases())->mapWithKeys(fn ($type) => [$type->value => $type->label()])->all()" required />
                <x-admin.select wire:model="form.locale" :label="__('admin.products.documents.locale')" :placeholder="__('admin.products.documents.all_locales')" :options="collect(config('app.supported_locales'))->mapWithKeys(fn ($locale) => [$locale => __('admin.locale.'.$locale)])->all()" :hint="__('admin.products.documents.locale_hint')" />
            </div>

            <fieldset>
                <legend class="text-sm font-semibold">{{ __('admin.products.documents.source') }}</legend>
                <div class="mt-2 flex rounded-lg bg-slate-100 p-1 text-sm font-semibold sm:w-72">
                    @foreach (['file' => __('admin.products.documents.file'), 'url' => __('admin.products.documents.url')] as $source => $sourceLabel)
                        <label class="flex-1 cursor-pointer">
                            <input type="radio" wire:model.live="form.source" value="{{ $source }}" class="peer sr-only">
                            <span class="flex h-8 items-center justify-center rounded-md text-slate-500 peer-checked:bg-white peer-checked:text-slate-900 peer-checked:shadow-sm peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500">{{ $sourceLabel }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            @if ($form->source === 'file')
                <div>
                    <label class="inline-flex h-10 cursor-pointer items-center gap-2 rounded-lg border-2 border-dashed border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 focus-within:ring-2 focus-within:ring-brand-500 hover:border-brand-500 hover:text-brand-700">
                        <input type="file" wire:model="form.file" accept=".pdf,.zip,.exe" class="sr-only" aria-describedby="document-file-hint">
                        <x-admin.icon name="upload" size="size-4" />
                        <span wire:loading.remove wire:target="form.file">{{ $form->file ? $form->file->getClientOriginalName() : ($form->id ? __('admin.products.documents.replace_file') : __('admin.products.documents.choose_file')) }}</span>
                        <span wire:loading wire:target="form.file">{{ __('admin.image_upload.uploading') }}</span>
                    </label>
                    <p id="document-file-hint" class="mt-1 text-xs text-slate-500">{{ __('admin.products.documents.file_hint') }}</p>
                    <x-admin.field-error name="form.file" />
                </div>
            @else
                <x-admin.input wire:model="form.url" type="url" :label="__('admin.products.documents.url')" placeholder="https://" maxlength="2048" :hint="__('admin.products.documents.url_hint')" required />
            @endif

            <div class="flex justify-end gap-2">
                <x-admin.button variant="secondary" size="sm" wire:click="cancel">{{ __('admin.common.cancel') }}</x-admin.button>
                <x-admin.button size="sm" wire:click="save" loading="save" data-document-save>{{ __('admin.common.save') }}</x-admin.button>
            </div>
        </div>
    @endif

    <ul class="divide-y divide-slate-100">
        @forelse ($this->documents as $document)
            <li wire:key="document-{{ $document->id }}" class="flex items-center gap-3 px-4 py-3 sm:px-6" data-document="{{ $document->id }}">
                <span @class(['grid size-10 shrink-0 place-items-center rounded-lg text-[10px] font-bold', 'bg-red-50 text-danger' => $document->kind() === 'PDF', 'bg-sky-50 text-sky-700' => $document->kind() === 'URL', 'bg-slate-100 text-slate-600' => ! in_array($document->kind(), ['PDF', 'URL'], true)])>{{ $document->kind() }}</span>
                <div class="min-w-0 flex-1">
                    <a href="{{ $document->adminUrl() }}" @unless ($document->isFile()) target="_blank" rel="noopener nofollow" @endunless class="block truncate text-sm font-medium hover:text-brand-700">{{ $document->title }}</a>
                    <p class="truncate text-xs text-slate-500">
                        {{ $document->type->label() }}
                        @if ($document->size) · {{ \Illuminate\Support\Number::fileSize($document->size, precision: 1) }}@endif
                        · <span class="font-semibold">{{ $document->locale ? strtoupper($document->locale) : __('admin.products.documents.all_locales') }}</span>
                    </p>
                </div>
                @if ($canEdit)
                    <button type="button" wire:click="edit({{ $document->id }})" class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="{{ __('admin.common.edit') }}: {{ $document->title }}"><x-admin.icon name="edit" size="size-4" /></button>
                    <button
                        type="button"
                        x-on:click="$dispatch('confirm', {
                            title: @js(__('admin.products.documents.delete_title')),
                            body: @js(__('admin.products.documents.delete_body', ['name' => $document->title])),
                            label: @js(__('admin.common.delete')),
                            component: $wire.$id, method: 'delete', params: [{{ $document->id }}],
                        })"
                        class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-danger"
                        aria-label="{{ __('admin.common.delete') }}: {{ $document->title }}"
                    ><x-admin.icon name="trash" size="size-4" /></button>
                @endif
            </li>
        @empty
            <li class="px-4 py-6 text-center text-sm text-slate-500 sm:px-6">{{ __('admin.products.documents.none') }}</li>
        @endforelse
    </ul>
</div>
