{{--
    SI yordamchi / AI assistant slide-over (plan 02 §2): right panel from sm,
    bottom sheet on phones. Opened by "ai-open" (WithAiAssist::openAi()).
--}}
@php
    $locales = config('app.supported_locales');
    $field = $this->field;
    $max = $field?->maxLength;
    $isHtml = (bool) $field?->isHtml();
    $warnings = (array) ($request?->brand_warnings ?? []);
@endphp

<div>
    <div
        x-data="{ open: $wire.entangle('open') }"
        x-cloak
        x-show="open"
        class="fixed inset-0 z-[60]"
        role="dialog"
        aria-modal="true"
        aria-labelledby="ai-assistant-title"
        x-on:keydown.escape.window="open = false"
        data-ai-assistant
    >
        <div x-show="open" x-transition.opacity class="absolute inset-0 bg-slate-900/50" x-on:click="open = false"></div>

        <div x-show="open" x-trap.noscroll="open" class="absolute inset-x-0 bottom-0 flex max-h-[92vh] flex-col rounded-t-2xl bg-white shadow-2xl sm:inset-y-0 sm:left-auto sm:right-0 sm:max-h-none sm:w-[34rem] sm:rounded-none">
            <div class="mx-auto mt-2 h-1 w-10 rounded-full bg-slate-200 sm:hidden"></div>

            {{-- Header --}}
            <div class="flex items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                <div class="min-w-0">
                    <h2 id="ai-assistant-title" class="flex items-center gap-2 text-base font-semibold">
                        <span class="grid size-7 place-items-center rounded-lg bg-violet-50 text-violet-600"><x-admin.icon name="sparkles" size="size-4" /></span>
                        {{ __('ai.assistant.title') }}
                    </h2>
                    @if ($field)
                        <p class="mt-1 truncate text-sm text-slate-500">{{ __('ai.assistant.field') }}: <span class="font-medium text-slate-700">{{ $field->label() }}</span></p>
                    @endif
                </div>
                <button type="button" wire:click="close" class="grid size-9 shrink-0 place-items-center rounded-lg text-slate-500 hover:bg-slate-100" aria-label="{{ __('admin.a11y.close') }}"><x-admin.icon name="x" /></button>
            </div>

            <div class="flex-1 space-y-5 overflow-y-auto px-5 py-5">
                {{-- Mode --}}
                <div class="flex rounded-lg bg-slate-100 p-1" role="radiogroup" aria-label="{{ __('ai.assistant.mode') }}">
                    <label class="flex h-9 flex-1 cursor-pointer items-center justify-center rounded-md px-3 text-center text-xs font-semibold has-checked:bg-white has-checked:text-slate-900 has-checked:shadow-sm text-slate-500">
                        <input type="radio" wire:model.live="mode" value="generate" class="sr-only">
                        {{ __('ai.assistant.mode_generate') }}
                    </label>
                    <label @class(['flex h-9 flex-1 items-center justify-center rounded-md px-3 text-center text-xs font-semibold has-checked:bg-white has-checked:text-slate-900 has-checked:shadow-sm text-slate-500', 'cursor-pointer' => $this->canTranslate(), 'cursor-not-allowed opacity-50' => ! $this->canTranslate()]) @if (! $this->canTranslate()) title="{{ __('ai.assistant.translate_empty', ['locale' => strtoupper($locale)]) }}" @endif>
                        <input type="radio" wire:model.live="mode" value="translate" class="sr-only" @disabled(! $this->canTranslate())>
                        {{ __('ai.assistant.mode_translate', ['locale' => strtoupper($locale)]) }}
                    </label>
                </div>

                {{-- Hint --}}
                <div>
                    <div class="flex items-center justify-between gap-2">
                        <label for="ai-hint" class="text-sm font-semibold">{{ __('ai.assistant.hint') }}</label>
                        <button type="button" wire:click="resetHint" class="text-xs font-semibold text-brand-600 hover:text-brand-700">{{ __('ai.assistant.hint_reset') }}</button>
                    </div>
                    <textarea id="ai-hint" wire:model="hint" rows="4" maxlength="{{ \App\Actions\Ai\QueueTextRequest::HINT_MAX }}" aria-describedby="ai-hint-help" class="mt-1.5 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-base outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 sm:text-sm"></textarea>
                    <p id="ai-hint-help" class="mt-1 text-xs text-slate-500">{{ __('ai.assistant.hint_help') }}</p>
                    <x-admin.field-error name="hint" />
                    <x-admin.field-error name="source" />
                    <x-admin.field-error name="field" />
                    <x-admin.field-error name="locale" />
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <x-admin.button icon="sparkles" wire:click="generate" loading="generate" :disabled="$pending" data-ai-generate>
                        {{ $mode === 'translate' ? __('ai.assistant.translate') : __('ai.assistant.generate') }}
                    </x-admin.button>
                    @if ($remaining !== null)
                        <span class="text-xs text-slate-500" data-ai-remaining>{{ __('ai.assistant.remaining', ['count' => $remaining, 'limit' => $limit]) }}</span>
                    @endif
                </div>

                {{-- Waiting --}}
                @if ($pending)
                    <div wire:poll.1500ms="refreshRequest" class="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600" role="status" data-ai-pending>
                        <svg class="size-5 animate-spin text-violet-600" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".3" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                        {{ __('ai.assistant.pending') }}
                    </div>
                @endif

                {{-- Error --}}
                @if ($error)
                    <div class="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert" data-ai-error="{{ $error->value }}">
                        <x-admin.icon name="alert" size="size-5" class="shrink-0" />
                        <div class="min-w-0 flex-1">
                            <p>{{ $error->message() }}</p>
                            @if ($error->isRetryable())
                                <button type="button" wire:click="retry" class="mt-2 inline-flex h-8 items-center gap-1.5 rounded-lg bg-white px-3 text-xs font-semibold text-red-700 ring-1 ring-red-200 hover:bg-red-100" data-ai-retry>
                                    <x-admin.icon name="restore" size="size-3.5" />{{ __('ai.assistant.retry') }}
                                </button>
                            @endif
                        </div>
                    </div>
                @endif

                {{-- Preview cards --}}
                @if ($texts !== [])
                    <div class="space-y-3" data-ai-results>
                        @foreach ($locales as $code)
                            @php
                                $text = $texts[$code] ?? '';
                                $length = mb_strlen($isHtml ? html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8') : $text);
                                $source = $this->isSource($code);
                                $localeWarnings = $warnings[$code] ?? [];
                                $tooLong = $this->isTooLong($text);
                            @endphp
                            <article wire:key="ai-card-{{ $request->id }}-{{ $code }}" class="rounded-xl border border-slate-200 bg-white" lang="{{ $code }}" data-ai-card="{{ $code }}">
                                <header class="flex items-center gap-2 border-b border-slate-100 px-4 py-2.5">
                                    <span class="rounded bg-slate-900 px-1.5 py-0.5 text-[11px] font-bold uppercase text-white">{{ $code }}</span>
                                    @if ($source)
                                        <x-admin.badge color="muted">{{ __('ai.assistant.source') }}</x-admin.badge>
                                    @endif
                                    <span @class(['ml-auto text-xs font-medium tabular-nums', 'text-danger' => $max && $length > $max, 'text-slate-400' => ! $max || $length <= $max]) data-ai-count>{{ $length }}/{{ $max }}</span>
                                </header>
                                <div class="px-4 py-3 text-sm text-slate-800">
                                    @if ($isHtml)
                                        <div class="rich-text-content">{!! \App\Support\HtmlSanitizer::clean($text) !!}</div>
                                    @else
                                        <p class="whitespace-pre-line">{{ \App\Services\Ai\BrandGuard::highlight($text) }}</p>
                                    @endif

                                    @if ($localeWarnings !== [])
                                        <p class="mt-3 flex items-start gap-1.5 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800" data-ai-warning>
                                            <x-admin.icon name="alert" size="size-4" class="shrink-0" />
                                            <span>{{ __('ai.assistant.brand_warning') }} <strong>{{ implode(', ', $localeWarnings) }}</strong></span>
                                        </p>
                                    @endif

                                    @if ($code === 'uz' && ! $source)
                                        <p class="mt-3 flex items-start gap-1.5 rounded-lg bg-sky-50 px-3 py-2 text-xs text-sky-800" data-ai-review-note>
                                            <x-admin.icon name="info" size="size-4" class="shrink-0" />{{ __('ai.assistant.review_uz') }}
                                        </p>
                                    @endif
                                </div>
                                @unless ($source)
                                    <footer class="flex items-center justify-end gap-3 border-t border-slate-100 px-4 py-2.5">
                                        @if ($tooLong)
                                            <span class="mr-auto text-xs text-danger" data-ai-too-long>{{ __('ai.assistant.too_long', ['max' => $max]) }}</span>
                                        @endif
                                        <x-admin.button size="sm" variant="soft" icon="check" wire:click="apply('{{ $code }}')" :disabled="$tooLong" data-ai-apply="{{ $code }}">{{ __('ai.assistant.apply_locale', ['locale' => strtoupper($code)]) }}</x-admin.button>
                                    </footer>
                                @endunless
                            </article>
                        @endforeach
                    </div>
                    <p class="text-xs text-slate-500">{{ __('ai.assistant.apply_help') }}</p>
                @endif
            </div>

            <div class="flex gap-2 border-t border-slate-100 p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]">
                <button type="button" wire:click="close" class="h-11 flex-1 rounded-lg border border-slate-200 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('admin.common.cancel') }}</button>
                @php($applicable = collect($texts)->filter(fn ($text, $code) => $this->canApply($code, $text))->isNotEmpty())
                <button type="button" wire:click="applyAll" @disabled(! $applicable) class="h-11 flex-[2] rounded-lg bg-brand-600 text-sm font-semibold text-white hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-50" data-ai-apply-all>{{ __('ai.assistant.apply_all') }}</button>
            </div>
        </div>
    </div>
</div>
