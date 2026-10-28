{{-- FAQ editor (inside the category / product / company form: no <form>, Enter never submits the parent). --}}
<div x-on:keydown.enter="if ($event.target.tagName === 'INPUT') $event.preventDefault()" data-faq-items>
    <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3.5 sm:px-6">
        <div class="min-w-0">
            <h2 class="text-base font-semibold">{{ __('admin.faq.title') }}</h2>
            <p class="mt-0.5 text-xs text-slate-500">{{ $isGeneral ? __('admin.faq.help_general') : __('admin.faq.help') }}</p>
        </div>
        @if ($canEdit && ! $editing)
            <button type="button" wire:click="create" class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-lg bg-brand-50 px-3 text-sm font-semibold text-brand-700 hover:bg-brand-100" data-faq-add><x-admin.icon name="plus" size="size-4" />{{ __('admin.faq.add') }}</button>
        @endif
    </div>

    @if ($editing)
        <div class="space-y-4 border-b border-slate-100 bg-slate-50/60 p-4 sm:p-6" data-faq-editor>
            <x-admin.locale-tabs :error-keys="['form.question', 'form.answer']" :label="__('admin.form.content_language')">
                @foreach (config('app.supported_locales') as $locale)
                    <x-admin.locale-panel :locale="$locale">
                        <x-admin.input wire:model="form.question.{{ $locale }}" :label="__('admin.faq.fields.question')" :required="$locale === 'uz'" maxlength="{{ \App\Models\FaqItem::QUESTION_MAX }}" />
                        <x-admin.textarea wire:model="form.answer.{{ $locale }}" :label="__('admin.faq.fields.answer')" :required="$locale === 'uz'" rows="5" maxlength="{{ \App\Models\FaqItem::ANSWER_MAX }}" :hint="__('admin.faq.answer_hint')" />
                    </x-admin.locale-panel>
                @endforeach
            </x-admin.locale-tabs>

            <x-admin.toggle wire:model="form.is_active" :label="__('admin.faq.fields.is_active')" :description="__('admin.faq.is_active_hint')" />

            <div class="flex justify-end gap-2">
                <x-admin.button variant="secondary" size="sm" wire:click="cancel">{{ __('admin.common.cancel') }}</x-admin.button>
                <x-admin.button size="sm" wire:click="save" loading="save" data-faq-save>{{ __('admin.common.save') }}</x-admin.button>
            </div>
        </div>
    @endif

    @php
        $activePosition = 0;
    @endphp
    <ol class="divide-y divide-slate-100">
        @forelse ($this->items as $item)
            @php
                // Admin UI language, falling back to uz.
                $question = $item->getTranslation('question', app()->getLocale());
                $answer = $item->getTranslation('answer', app()->getLocale());
                $activePosition += $item->is_active ? 1 : 0;
                $beyondHome = $isGeneral && $item->is_active && $activePosition > \App\Support\Site\FaqContent::HOME_LIMIT;
            @endphp
            <li wire:key="faq-{{ $item->id }}" @class(['flex items-start gap-3 px-4 py-3 sm:px-6', 'bg-slate-50/70' => $beyondHome]) data-faq-item="{{ $item->id }}" @if ($beyondHome) data-faq-beyond-home @endif>
                <span class="mt-0.5 w-5 shrink-0 text-right text-xs tabular-nums text-slate-400">{{ $loop->iteration }}</span>
                <div class="min-w-0 flex-1">
                    <p @class(['text-sm font-medium', 'text-slate-500' => $beyondHome])>{{ $question }}</p>
                    <p class="mt-0.5 line-clamp-2 text-xs text-slate-500">{{ $answer }}</p>
                    <p class="mt-1 flex flex-wrap items-center gap-1.5 text-[11px] font-semibold text-slate-400">
                        @foreach (config('app.supported_locales') as $locale)
                            <span @class(['rounded px-1', 'bg-brand-50 text-brand-700' => filled($item->getTranslation('question', $locale, false)), 'bg-slate-100 line-through' => blank($item->getTranslation('question', $locale, false))])>{{ strtoupper($locale) }}</span>
                        @endforeach
                        @unless ($item->is_active)
                            <x-admin.badge color="muted">{{ __('admin.faq.hidden') }}</x-admin.badge>
                        @endunless
                        @if ($beyondHome)
                            <x-admin.badge color="amber">{{ __('admin.faq.not_on_home', ['limit' => \App\Support\Site\FaqContent::HOME_LIMIT]) }}</x-admin.badge>
                        @endif
                    </p>
                </div>
                @if ($canEdit)
                    <div class="flex shrink-0 items-center">
                        <button type="button" wire:click="move({{ $item->id }}, -1)" @disabled($loop->first) class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30" aria-label="{{ __('admin.common.move_up') }}: {{ $question }}"><x-admin.icon name="chevron-down" size="size-4" class="rotate-180" /></button>
                        <button type="button" wire:click="move({{ $item->id }}, 1)" @disabled($loop->last) class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30" aria-label="{{ __('admin.common.move_down') }}: {{ $question }}"><x-admin.icon name="chevron-down" size="size-4" /></button>
                        <button type="button" wire:click="edit({{ $item->id }})" class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="{{ __('admin.common.edit') }}: {{ $question }}"><x-admin.icon name="edit" size="size-4" /></button>
                        <button
                            type="button"
                            x-on:click="$dispatch('confirm', {
                                title: @js(__('admin.faq.delete_title')),
                                body: @js(__('admin.faq.delete_body', ['name' => $question])),
                                label: @js(__('admin.common.delete')),
                                component: $wire.$id, method: 'delete', params: [{{ $item->id }}],
                            })"
                            class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-danger"
                            aria-label="{{ __('admin.common.delete') }}: {{ $question }}"
                        ><x-admin.icon name="trash" size="size-4" /></button>
                    </div>
                @endif
            </li>
        @empty
            <li class="px-4 py-6 text-center text-sm text-slate-500 sm:px-6">{{ $isGeneral ? __('admin.faq.none_general') : __('admin.faq.none') }}</li>
        @endforelse
    </ol>
</div>
