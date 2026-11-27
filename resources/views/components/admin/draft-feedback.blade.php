@props([
    'prefix' => 'feedback',
    'submit' => 'submitFeedback',
])

{{--
    Plan 46 §2.2: what staff did with the AI draft above (used, edited, rejected). Texts are redacted and
    stored encrypted for 30 days; the server re-checks access and that this is the latest draft.
--}}
@php($t = 'office.feedback')
<form wire:submit="{{ $submit }}" class="mt-3 space-y-2 rounded-lg border border-slate-200 p-3 text-sm" data-draft-feedback novalidate>
    <p class="font-semibold">{{ __($t.'.title') }}</p>
    <p class="flex items-start gap-1.5 text-xs text-amber-800" role="note" data-feedback-warning><x-admin.icon name="alert" size="size-3.5" class="mt-0.5 shrink-0" />{{ __($t.'.warning') }}</p>
    <div class="grid gap-2 sm:grid-cols-2">
        <x-admin.select wire:model.live="{{ $prefix }}.outcome" :label="__($t.'.outcome')" :placeholder="__($t.'.choose')" :options="collect(\App\Enums\DraftOutcome::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" />
        <x-admin.select wire:model="{{ $prefix }}.reason" :label="__($t.'.reason')" :placeholder="__($t.'.choose')" :options="collect(\App\Enums\DraftFeedbackReason::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" />
    </div>
    <x-admin.textarea wire:model="{{ $prefix }}.final_text" :label="__($t.'.final_text')" rows="3" maxlength="1500" />
    <x-admin.input wire:model="{{ $prefix }}.note" :label="__($t.'.note')" maxlength="500" />
    @error('feedback')<p class="text-xs text-danger" role="alert" data-feedback-error>{{ $message }}</p>@enderror
    <x-admin.button type="submit" size="sm" icon="check" loading="{{ $submit }}">{{ __($t.'.submit') }}</x-admin.button>
</form>
