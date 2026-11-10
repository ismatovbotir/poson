@props(['open' => false, 'prompt' => '', 'report' => []])

{{--
    "AI prompt" + "Fill from AI answer" box of a content form (plan 29, WithAiFill).
    No AI runs here: the admin copies the prompt into an external AI and pastes
    the JSON answer back. The pasted text lives only in this Alpine scope and is
    sent as a method argument (never a Livewire property); the server writes the
    valid values into the form, nothing is saved until Save.
    The prompt block is wire:ignore'd: rendered once per open.
--}}
@php($maxBytes = \App\Support\Content\AiFill\AiAnswerParser::MAX_BYTES)

<section data-ai-fill x-data="{ expanded: @js((bool) $open || ! empty($report)) }" class="rounded-2xl border border-slate-200 bg-white">
    <button type="button" x-on:click="expanded = ! expanded" x-bind:aria-expanded="expanded.toString()" class="flex w-full items-center justify-between gap-3 px-4 py-3.5 text-left sm:px-6">
        <span class="flex min-w-0 items-center gap-2">
            <x-admin.icon name="sparkles" size="size-5" class="shrink-0 text-brand-600" />
            <span class="min-w-0">
                <span class="block text-base font-semibold">{{ __('admin.ai_fill.title') }}</span>
                <span class="block text-sm text-slate-500">{{ __('admin.ai_fill.subtitle') }}</span>
            </span>
        </span>
        <x-admin.icon name="chevron-down" size="size-5" class="shrink-0 text-slate-400 transition" x-bind:class="expanded ? 'rotate-180' : ''" />
    </button>

    <div x-cloak x-show="expanded" class="space-y-5 border-t border-slate-100 p-4 sm:p-6">
        <div class="space-y-2">
            <h3 class="text-sm font-semibold">{{ __('admin.ai_fill.step_prompt') }}</h3>
            <p class="text-sm text-slate-500">{{ __('admin.ai_fill.step_prompt_help') }}</p>

            <x-admin.button variant="secondary" size="sm" wire:click="toggleAiPrompt" wire:loading.attr="disabled" wire:target="toggleAiPrompt" aria-expanded="{{ $open ? 'true' : 'false' }}">
                {{ $open ? __('admin.ai_fill.hide_prompt') : __('admin.ai_fill.show_prompt') }}
            </x-admin.button>

            @if ($open && $prompt !== '')
                <div wire:ignore wire:key="ai-fill-prompt">
                    <x-admin.copy-block :value="$prompt" snippet="ai-prompt" plain class="max-h-80 overflow-y-auto" />
                </div>
            @endif
        </div>

        <div
            wire:ignore
            x-data="{
                text: '',
                onlyEmpty: true,
                busy: false,
                get bytes() { return new Blob([this.text]).size; },
                get tooLarge() { return this.bytes > {{ $maxBytes }}; },
                async apply() {
                    if (this.busy || this.tooLarge || this.text.trim() === '') { return; }
                    this.busy = true;
                    try { await $wire.applyAiAnswer(this.text, this.onlyEmpty); } finally { this.busy = false; }
                },
            }"
            class="space-y-3"
        >
            <h3 class="text-sm font-semibold">{{ __('admin.ai_fill.step_answer') }}</h3>
            <p class="text-sm text-slate-500">{{ __('admin.ai_fill.step_answer_help') }}</p>

            <label for="ai-fill-answer" class="sr-only">{{ __('admin.ai_fill.answer_label') }}</label>
            <textarea
                id="ai-fill-answer"
                x-model="text"
                rows="6"
                maxlength="{{ $maxBytes }}"
                spellcheck="false"
                autocomplete="off"
                placeholder="{{ __('admin.ai_fill.answer_placeholder') }}"
                class="block w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 font-mono text-base outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 sm:text-xs"
            ></textarea>
            <p class="text-xs tabular-nums" x-bind:class="tooLarge ? 'text-danger' : 'text-slate-400'" x-text="bytes + ' / {{ $maxBytes }} B'"></p>

            <label class="flex cursor-pointer items-center gap-2 text-sm">
                <input type="checkbox" x-model="onlyEmpty" class="size-4 rounded border-slate-300 text-brand-600">
                <span>{{ __('admin.ai_fill.only_empty') }}</span>
            </label>

            <div class="flex flex-wrap items-center gap-2">
                <button type="button" x-on:click="apply()" x-bind:disabled="busy || tooLarge || text.trim() === ''" class="inline-flex h-11 items-center justify-center gap-2 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-50 lg:h-10">
                    {{ __('admin.ai_fill.apply') }}
                </button>
                <button type="button" x-on:click="text = ''" x-show="text !== ''" class="inline-flex h-11 items-center rounded-lg px-3 text-sm font-semibold text-slate-600 hover:bg-slate-100 lg:h-10">{{ __('admin.ai_fill.clear') }}</button>
            </div>
        </div>

        <div aria-live="polite" data-ai-fill-report>
            @if ($report !== [])
                <div class="space-y-2 rounded-lg bg-slate-50 p-3 text-sm">
                    <p class="font-semibold text-slate-800">{{ __('admin.ai_fill.result', ['filled' => $report['filled'] ?? 0, 'skipped' => $report['skipped'] ?? 0]) }}</p>
                    @if (! empty($report['errors']))
                        <ul class="list-disc space-y-0.5 pl-5 text-danger">
                            @foreach ($report['errors'] as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    @endif
                    @if (! empty($report['warnings']))
                        <ul class="list-disc space-y-0.5 pl-5 text-amber-800">
                            @foreach ($report['warnings'] as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    @endif
                    <p class="text-xs text-slate-500">{{ __('admin.ai_fill.review_hint') }}</p>
                </div>
            @endif
        </div>
    </div>
</section>
