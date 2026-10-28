@props(['field' => null, 'target' => null])

{{--
    AI assist button (plan 02 §2). field = FieldRegistry key ("product.description"),
    target = the form property it fills ("form.name", whitelisted by the
    component's WithAiAssist::aiTargets()). Opens the assistant for the
    active locale tab (the closest [lang] = <x-admin.locale-panel>).
    Renders nothing without a target (image alt: phase 2) or when AI is off,
    not configured or the user lacks ai.use.
--}}
@if ($target && \App\Support\Ai\FieldRegistry::has((string) $field) && \App\Services\Ai\AiAssist::availableFor(auth()->user()))
    <button
        type="button"
        x-on:click="$wire.openAi(@js($target), $el.closest('[role=tabpanel][lang]')?.getAttribute('lang') ?? @js(config('app.supported_locales')[0]))"
        {{ $attributes->class(['inline-flex h-7 items-center gap-1 rounded-md bg-violet-50 px-2 text-xs font-semibold text-violet-700 hover:bg-violet-100 focus-visible:outline-2 focus-visible:outline-violet-500']) }}
        title="{{ __('ai.button.title') }}"
        aria-label="{{ __('ai.button.title') }}"
        data-ai-button="{{ $field }}"
    >
        <x-admin.icon name="sparkles" size="size-3.5" />
        <span aria-hidden="true">{{ __('ai.button.label') }}</span>
    </button>
@endif
