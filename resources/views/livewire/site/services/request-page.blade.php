{{--
    "Request repair / service" (App\Livewire\Site\Services\RequestPage). Same form markup
    as lead-form.blade.php: labels tied to inputs, errors linked with aria-describedby,
    the success block takes focus, "fax_ext" is an off-screen honeypot.
--}}
@php
    $inputClass = 'h-12 w-full rounded-btn border-[1.5px] bg-white px-4 text-ink transition-colors placeholder:text-ink-2 focus:border-brand-600 focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600';
    $fields = [
        'name' => ['type' => 'text', 'autocomplete' => 'name', 'required' => true, 'maxlength' => 120],
        'phone' => ['type' => 'tel', 'autocomplete' => 'tel', 'required' => true, 'maxlength' => 32],
        'company' => ['type' => 'text', 'autocomplete' => 'organization', 'required' => false, 'maxlength' => 160],
        'device_brand' => ['type' => 'text', 'autocomplete' => 'off', 'required' => true, 'maxlength' => 80],
        'device_model' => ['type' => 'text', 'autocomplete' => 'off', 'required' => true, 'maxlength' => 120],
        'serial_number' => ['type' => 'text', 'autocomplete' => 'off', 'required' => false, 'maxlength' => 64],
    ];
    $privacyLink = \App\Support\Site\PageDirectory::isActive('privacy')
        ? '<a href="'.e(\App\Support\Site\Navigation::page('privacy')).'" class="font-medium text-brand-600 underline hover:text-brand-700">'.e(__('site.services.request.consent_privacy')).'</a>'
        : e(__('site.services.request.consent_privacy'));
@endphp

<div class="site-container">
    @push('head')
        @foreach ($jsonLd as $block)
            <script type="application/ld+json">{!! $block !!}</script>
        @endforeach
    @endpush

    <x-site.breadcrumbs :items="$breadcrumbs" />

    <div class="grid items-start gap-8 md:grid-cols-2 md:gap-12" data-service-request>
        <div>
            <h1 class="text-[26px] leading-[1.15] font-bold tracking-[-0.025em] sm:text-[34px]">{{ __('site.services.request.title') }}</h1>
            <p class="mt-4 text-lg text-ink-2">{{ __('site.services.request.intro') }}</p>
            <ol class="mt-6 grid gap-3">
                @foreach ([1, 2, 3] as $step)
                    <li class="flex items-start gap-3">
                        <span class="grid size-8 shrink-0 place-items-center rounded-full bg-brand-50 font-bold text-brand-600" aria-hidden="true">{{ $step }}</span>
                        <span class="pt-1">{{ __("site.services.request.steps.{$step}") }}</span>
                    </li>
                @endforeach
            </ol>
        </div>

        <section id="request" class="rounded-box-lg bg-white p-5 shadow-card sm:p-7" aria-labelledby="service-request-title">
            <h2 id="service-request-title" class="mb-4 text-xl font-bold">{{ __('site.services.request.form_title') }}</h2>

            <div role="status" aria-live="polite" aria-atomic="true">
                @if ($sent)
                    <div class="flex flex-col items-start gap-3 py-4 focus:outline-none" tabindex="-1" x-init="$nextTick(() => $el.focus())" data-lead-success>
                        <span class="grid size-12 place-items-center rounded-full bg-brand-50 text-brand-600">
                            <x-site.icon name="check" size="size-6" />
                        </span>
                        <h3 class="text-xl font-bold">{{ __('site.services.request.success_title') }}</h3>
                        <p class="text-ink-2">{{ __('site.services.request.success_text') }}</p>
                    </div>
                @endif
            </div>

            @unless ($sent)
                <form wire:submit="submit" novalidate class="relative grid gap-3.5">
                    <div role="alert">
                        @error('form')
                            <p class="rounded-btn border border-danger/30 bg-danger/5 px-4 py-3 text-sm font-medium text-danger" data-form-error>{{ $message }}</p>
                        @enderror
                    </div>

                    @foreach ($fields as $field => $options)
                        <div class="grid gap-1.5" wire:key="service-field-{{ $field }}">
                            <label for="service-{{ $field }}" class="text-sm font-semibold">
                                {{ __("site.services.request.{$field}.label") }}
                                @if ($options['required'])
                                    <span class="text-danger" aria-hidden="true">*</span>
                                @endif
                            </label>
                            <input
                                id="service-{{ $field }}"
                                type="{{ $options['type'] }}"
                                wire:model="{{ $field }}"
                                autocomplete="{{ $options['autocomplete'] }}"
                                maxlength="{{ $options['maxlength'] }}"
                                @if ($field === 'phone') inputmode="tel" @endif
                                placeholder="{{ __("site.services.request.{$field}.placeholder") }}"
                                @if ($options['required']) aria-required="true" @endif
                                @error($field) aria-invalid="true" aria-describedby="service-{{ $field }}-error" @enderror
                                @class([$inputClass, 'border-danger' => $errors->has($field), 'border-line' => ! $errors->has($field)])
                            >
                            @error($field)
                                <p id="service-{{ $field }}-error" class="text-sm text-danger">{{ $message }}</p>
                            @enderror
                        </div>
                    @endforeach

                    <div class="grid gap-1.5">
                        <label for="service-message" class="text-sm font-semibold">{{ __('site.services.request.message.label') }} <span class="text-danger" aria-hidden="true">*</span></label>
                        <textarea
                            id="service-message"
                            wire:model="message"
                            rows="4"
                            maxlength="2000"
                            aria-required="true"
                            placeholder="{{ __('site.services.request.message.placeholder') }}"
                            @error('message') aria-invalid="true" aria-describedby="service-message-error" @enderror
                            @class([str_replace('h-12', 'min-h-28 py-3', $inputClass), 'border-danger' => $errors->has('message'), 'border-line' => ! $errors->has('message')])
                        ></textarea>
                        @error('message')
                            <p id="service-message-error" class="text-sm text-danger">{{ $message }}</p>
                        @enderror
                    </div>

                    <fieldset class="grid gap-1.5" @error('preferred_contact') aria-invalid="true" aria-describedby="service-preferred_contact-error" @enderror>
                        <legend class="mb-1.5 text-sm font-semibold">{{ __('site.services.request.preferred_contact.label') }} <span class="text-danger" aria-hidden="true">*</span></legend>
                        <div class="flex flex-wrap gap-x-6 gap-y-2">
                            @foreach ($preferredContacts as $contact)
                                <label class="inline-flex items-center gap-2" wire:key="service-contact-{{ $contact->value }}">
                                    <input type="radio" wire:model="preferred_contact" value="{{ $contact->value }}" class="size-4 accent-brand-600">
                                    {{ __("site.services.request.preferred_contact.options.{$contact->value}") }}
                                </label>
                            @endforeach
                        </div>
                        @error('preferred_contact')
                            <p id="service-preferred_contact-error" class="text-sm text-danger">{{ $message }}</p>
                        @enderror
                    </fieldset>

                    {{-- Honeypot: off-screen and skipped by keyboard and screen readers. --}}
                    <div class="absolute -left-[10000px] size-px overflow-hidden" aria-hidden="true">
                        <input type="text" id="service-fax-ext" name="fax_ext" wire:model="fax_ext" tabindex="-1" autocomplete="off">
                    </div>

                    <button
                        type="submit"
                        class="mt-1 inline-flex h-[54px] w-full items-center justify-center gap-2 rounded-btn bg-brand-600 px-[30px] text-base font-semibold text-white transition-colors hover:bg-brand-700 disabled:cursor-wait disabled:opacity-80"
                        wire:loading.attr="disabled"
                        wire:target="submit"
                    >
                        <span wire:loading.remove wire:target="submit">{{ __('site.services.request.submit') }}</span>
                        <span wire:loading wire:target="submit">{{ __('site.services.request.sending') }}</span>
                    </button>

                    <p class="text-xs text-ink-2">{!! __('site.services.request.consent', ['privacy' => $privacyLink]) !!}</p>
                </form>
            @endunless
        </section>
    </div>
</div>
