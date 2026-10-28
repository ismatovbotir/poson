@php
    $t = 'admin.ai_prompts';
    $max = \App\Support\Ai\PromptTemplate::MAX_LENGTH;
    $uiLocale = in_array(app()->getLocale(), array_keys($defaults), true) ? app()->getLocale() : array_key_first($defaults);
@endphp

{{-- Settings → AI prompts → one field (plan 12). --}}
<form wire:submit="save" novalidate>
    <x-admin.page-header :title="$field->label()" :back-href="route('admin.settings.ai-prompts.index')" :back-label="__($t.'.title')">
        <x-slot:actions>
            <div class="hidden gap-2 lg:flex">
                @if ($override)
                    <x-admin.button variant="secondary" icon="restore" wire:click="resetToDefault" wire:confirm="{{ __($t.'.reset_confirm') }}" data-prompt-reset>{{ __($t.'.reset') }}</x-admin.button>
                @endif
                <x-admin.button type="submit" icon="check" loading="save" :disabled="$unknown !== []">{{ __('admin.common.save') }}</x-admin.button>
            </div>
        </x-slot:actions>
    </x-admin.page-header>

    {{-- Field facts --}}
    <dl class="mt-4 flex flex-wrap gap-x-6 gap-y-2 text-sm text-slate-600" data-prompt-facts>
        <div class="flex items-center gap-1.5"><dt class="font-semibold text-slate-500">{{ __($t.'.fields.key') }}</dt><dd class="font-mono text-xs">{{ $field->key }}</dd></div>
        <div class="flex items-center gap-1.5"><dt class="font-semibold text-slate-500">{{ __($t.'.fields.group') }}</dt><dd>{{ __($t.'.groups.'.$field->group) }}</dd></div>
        <div class="flex items-center gap-1.5"><dt class="font-semibold text-slate-500">{{ __($t.'.fields.type') }}</dt><dd>{{ __($t.'.types.'.$field->type) }}</dd></div>
        <div class="flex items-center gap-1.5"><dt class="font-semibold text-slate-500">{{ __($t.'.fields.format') }}</dt><dd>{{ __($t.'.formats.'.$field->format) }}</dd></div>
        <div class="flex items-center gap-1.5"><dt class="font-semibold text-slate-500">{{ __($t.'.fields.max') }}</dt><dd>{{ __($t.'.max_chars', ['max' => $field->maxLength]) }}</dd></div>
        <div class="flex items-center gap-1.5">
            <dt class="sr-only">{{ __($t.'.fields.status') }}</dt>
            <dd>
                @if ($override)
                    <x-admin.badge color="violet" data-prompt-customized>{{ __($t.'.customized') }}</x-admin.badge>
                    <span class="ml-1 text-xs text-slate-500">{{ __($t.'.customized_by', ['name' => $override->updatedBy?->name ?? __($t.'.deleted_user'), 'date' => \App\Support\DateFormat::iso($override->updated_at->copy()->setTimezone(\App\Models\AiPromptOverride::TIMEZONE), 'D MMM YYYY, HH:mm')]) }}</span>
                @else
                    <x-admin.badge color="muted" data-prompt-default>{{ __($t.'.default') }}</x-admin.badge>
                @endif
            </dd>
        </div>
    </dl>

    <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-3 lg:gap-6">
        <div class="min-w-0 space-y-4 lg:col-span-2 lg:space-y-6">
            {{-- Prompt --}}
            <x-admin.card :title="__($t.'.prompt')" :description="__($t.'.prompt_help')">
                <div
                    x-data="{
                        insert(token) {
                            const el = this.$refs.prompt;
                            const start = el.selectionStart ?? el.value.length;
                            const end = el.selectionEnd ?? start;
                            el.setRangeText(token, start, end, 'end');
                            el.dispatchEvent(new Event('input', { bubbles: true }));
                            el.focus();
                        },
                    }"
                    class="space-y-4"
                >
                    <x-admin.textarea wire:model.live.debounce.500ms="prompt" name="prompt" x-ref="prompt" :label="__($t.'.prompt')" rows="9" maxlength="{{ $max }}" :hint="__($t.'.length_hint', ['min' => \App\Support\Ai\PromptTemplate::MIN_LENGTH, 'max' => $max])" data-prompt-input>
                        <x-slot:labelAside>
                            <span class="ml-auto text-xs font-medium tabular-nums" x-bind:class="(($wire.prompt ?? '').length > {{ $max }}) ? 'text-danger' : 'text-slate-400'" x-text="(($wire.prompt ?? '').length) + '/{{ $max }}'" data-prompt-counter>{{ $length }}/{{ $max }}</span>
                        </x-slot:labelAside>
                    </x-admin.textarea>

                    <div>
                        <p class="text-xs font-semibold text-slate-500">{{ __($t.'.placeholders') }}</p>
                        <div class="mt-1.5 flex flex-wrap gap-2">
                            @foreach ($field->placeholders as $placeholder)
                                <button type="button" x-on:click="insert(@js('{'.$placeholder.'}'))" class="inline-flex h-8 items-center rounded-md bg-slate-100 px-2.5 font-mono text-xs font-semibold text-slate-700 hover:bg-slate-200 focus-visible:outline-2 focus-visible:outline-brand-500" title="{{ __($t.'.placeholder_help.'.$placeholder) }}" data-placeholder-chip="{{ $placeholder }}">{{ '{'.$placeholder.'}' }}</button>
                            @endforeach
                        </div>
                        <p class="mt-1.5 text-xs text-slate-500">{{ __($t.'.placeholders_hint') }}</p>
                    </div>

                    @if ($unknown !== [])
                        <p class="flex items-start gap-2 rounded-lg bg-red-50 px-3 py-2.5 text-sm text-danger" role="alert" data-prompt-unknown>
                            <x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.errors.unknown_placeholders', ['list' => implode(', ', $unknown)]) }}
                        </p>
                    @endif
                    @if ($missing !== [])
                        <p class="flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2.5 text-sm text-amber-800" role="note" data-prompt-missing>
                            <x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.warnings.missing_placeholders', ['list' => implode(', ', array_map(fn (string $name): string => '{'.$name.'}', $missing))]) }}
                        </p>
                    @endif
                    @if ($brandWarnings !== [])
                        <p class="flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2.5 text-sm text-amber-800" role="note" data-prompt-brand>
                            <x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.warnings.brand_words', ['list' => implode(', ', $brandWarnings)]) }}
                        </p>
                    @endif
                </div>
            </x-admin.card>

            {{-- Preview --}}
            <x-admin.card :title="__($t.'.preview')" :description="__($t.'.preview_help')">
                <div class="relative">
                    <p class="whitespace-pre-wrap break-words text-sm text-slate-700" data-prompt-preview>{{ $preview !== '' ? $preview : __('admin.common.empty_value') }}</p>
                    <span wire:loading wire:target="prompt" class="absolute right-0 top-0 text-xs text-slate-400">{{ __($t.'.updating') }}</span>
                </div>
            </x-admin.card>
        </div>

        <aside class="min-w-0 space-y-4 lg:sticky lg:top-24 lg:self-start lg:space-y-6">
            {{-- Default (read-only) per locale --}}
            <x-admin.card :title="__($t.'.default_prompt')" :description="__($t.'.default_help')">
                <div x-data="{ locale: @js($uiLocale) }" data-prompt-defaults>
                    <div class="inline-flex rounded-lg bg-slate-100 p-0.5" role="tablist" aria-label="{{ __($t.'.default_prompt') }}">
                        @foreach ($defaults as $locale => $text)
                            <button type="button" role="tab" x-on:click="locale = @js($locale)" x-bind:aria-selected="(locale === @js($locale)).toString()" x-bind:class="locale === @js($locale) ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500'" class="h-8 rounded-md px-3 text-xs font-bold uppercase">{{ $locale }}</button>
                        @endforeach
                    </div>
                    @foreach ($defaults as $locale => $text)
                        <p x-show="locale === @js($locale)" @if ($locale !== $uiLocale) x-cloak @endif lang="{{ $locale }}" class="mt-3 whitespace-pre-wrap break-words rounded-lg bg-canvas p-3 text-sm text-slate-700" data-prompt-default-text="{{ $locale }}">{{ $text }}</p>
                    @endforeach
                </div>
            </x-admin.card>

            <p class="flex items-start gap-2 rounded-lg bg-slate-50 px-3 py-2.5 text-xs text-slate-600" role="note" data-prompt-fixed-rules>
                <x-admin.icon name="info" size="size-4" class="shrink-0 text-slate-400" />{{ __($t.'.fixed_rules') }}
            </p>
        </aside>
    </div>

    {{-- Phones: actions at the bottom --}}
    <div class="mt-6 flex flex-col-reverse gap-2 lg:hidden">
        @if ($override)
            <x-admin.button variant="secondary" icon="restore" wire:click="resetToDefault" wire:confirm="{{ __($t.'.reset_confirm') }}">{{ __($t.'.reset') }}</x-admin.button>
        @endif
        <x-admin.button type="submit" icon="check" loading="save" :disabled="$unknown !== []">{{ __('admin.common.save') }}</x-admin.button>
    </div>
</form>
