{{--
    Lead form (App\Livewire\Site\LeadForm). Labels are tied to inputs, errors are
    linked with aria-describedby, and the success / rate-limit messages are announced
    through live regions (the success block also takes focus). "fax_ext" is a
    honeypot, positioned off-screen.
--}}
@php
    $inputClass = 'h-12 w-full rounded-btn border-[1.5px] bg-white px-4 text-ink transition-colors placeholder:text-ink-2 focus:border-brand-600 focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600';
    $fields = [
        'name' => ['type' => 'text', 'autocomplete' => 'name', 'required' => true, 'maxlength' => 120],
        'phone' => ['type' => 'tel', 'autocomplete' => 'tel', 'required' => true, 'maxlength' => 32],
        'company' => ['type' => 'text', 'autocomplete' => 'organization', 'required' => false, 'maxlength' => 160],
    ];
    $privacyLink = '<a href="'.e(\App\Support\Site\Navigation::page('privacy')).'" class="font-medium text-brand-600 underline hover:text-brand-700">'.e(__('site.lead_form.consent_privacy')).'</a>';
@endphp

<div class="rounded-box-lg bg-white p-5 shadow-card sm:p-7">
    <div role="status" aria-live="polite" aria-atomic="true">
        @if ($sent)
            <div class="flex flex-col items-start gap-3 py-4 focus:outline-none" tabindex="-1" x-init="$nextTick(() => $el.focus())" data-lead-success>
                <span class="grid size-12 place-items-center rounded-full bg-brand-50 text-brand-600">
                    <x-site.icon name="check" size="size-6" />
                </span>
                <h3 class="text-xl font-bold">{{ __('site.lead_form.success_title') }}</h3>
                <p class="text-ink-2">{{ __('site.lead_form.success_text') }}</p>
            </div>
        @endif
    </div>

    @unless ($sent)
        <form wire:submit="submit" novalidate class="relative grid gap-3.5">
            @if ($productLabel)
                <p class="rounded-btn bg-bg-soft px-4 py-3 text-sm" data-lead-product>
                    <span class="text-ink-2">{{ __('site.lead_form.product') }}:</span>
                    <span class="font-semibold">{{ $productLabel }}</span>
                </p>
            @endif

            <div role="alert">
                @error('form')
                    <p class="rounded-btn border border-danger/30 bg-danger/5 px-4 py-3 text-sm font-medium text-danger" data-form-error>{{ $message }}</p>
                @enderror
            </div>

            @foreach ($fields as $field => $options)
                <div class="grid gap-1.5" wire:key="lead-field-{{ $field }}">
                    <label for="lead-{{ $field }}" class="text-sm font-semibold">
                        {{ __("site.lead_form.{$field}") }}
                        @if ($options['required'])
                            <span class="text-danger" aria-hidden="true">*</span>
                        @endif
                    </label>
                    <input
                        id="lead-{{ $field }}"
                        type="{{ $options['type'] }}"
                        wire:model="{{ $field }}"
                        autocomplete="{{ $options['autocomplete'] }}"
                        maxlength="{{ $options['maxlength'] }}"
                        @if ($field === 'phone') inputmode="tel" @endif
                        placeholder="{{ __("site.lead_form.{$field}_placeholder") }}"
                        @if ($options['required']) aria-required="true" @endif
                        @error($field) aria-invalid="true" aria-describedby="lead-{{ $field }}-error" @enderror
                        @class([$inputClass, 'border-danger' => $errors->has($field), 'border-line' => ! $errors->has($field)])
                    >
                    @error($field)
                        <p id="lead-{{ $field }}-error" class="text-sm text-danger">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach

            <div class="grid gap-1.5">
                <label for="lead-message" class="text-sm font-semibold">{{ __('site.lead_form.message') }}</label>
                <textarea
                    id="lead-message"
                    wire:model="message"
                    rows="3"
                    maxlength="1000"
                    placeholder="{{ __('site.lead_form.message_placeholder') }}"
                    @error('message') aria-invalid="true" aria-describedby="lead-message-error" @enderror
                    @class([str_replace('h-12', 'min-h-24 py-3', $inputClass), 'border-danger' => $errors->has('message'), 'border-line' => ! $errors->has('message')])
                ></textarea>
                @error('message')
                    <p id="lead-message-error" class="text-sm text-danger">{{ $message }}</p>
                @enderror
            </div>

            {{-- Honeypot: off-screen and skipped by keyboard and screen readers. --}}
            <div class="absolute -left-[10000px] size-px overflow-hidden" aria-hidden="true">
                <input type="text" id="lead-fax-ext" name="fax_ext" wire:model="fax_ext" tabindex="-1" autocomplete="off">
            </div>

            <button
                type="submit"
                class="mt-1 inline-flex h-[54px] w-full items-center justify-center gap-2 rounded-btn bg-brand-600 px-[30px] text-base font-semibold text-white transition-colors hover:bg-brand-700 disabled:cursor-wait disabled:opacity-80"
                wire:loading.attr="disabled"
                wire:target="submit"
            >
                <span wire:loading.remove wire:target="submit">{{ __('site.lead_form.submit') }}</span>
                <span wire:loading wire:target="submit">{{ __('site.lead_form.sending') }}</span>
            </button>

            <p class="text-xs text-ink-2">{!! __('site.lead_form.consent', ['privacy' => $privacyLink]) !!}</p>
        </form>
    @endunless
</div>
