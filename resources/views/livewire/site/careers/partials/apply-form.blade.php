{{--
    Application form shared by the vacancy page and the open application page (plan 31 §7).
    A plain multipart POST to App\Http\Controllers\Site\SubmitJobApplicationController (not a
    Livewire upload): CSRF, the honeypot "fax_ext" (off-screen) and the bound form token.
    Errors come back in the "careers" error bag with the old input (never the file).
    Styled after the template's div.lead block and the site lead form.
    Expects: $heading, $form = ['enabled' => bool, 'action' => string, 'token' => string, 'cv' => bool].
--}}
@php
    $bag = session('errors')?->getBag(\App\Http\Requests\Site\SubmitJobApplicationRequest::ERROR_BAG) ?? new \Illuminate\Support\MessageBag;
    if (request()->query(\App\Support\Careers\CareersPostTooLarge::QUERY) === \App\Support\Careers\CareersPostTooLarge::VALUE && ! $bag->has('form')) {
        $bag->add('form', __('careers.site.form.cv_too_large'));
    }
    $sent = session(\App\Http\Controllers\Site\SubmitJobApplicationController::SENT_FLASH) === true;
    $inputClass = 'h-12 w-full rounded-btn border-[1.5px] bg-white px-4 text-ink transition-colors placeholder:text-ink-2 focus:border-brand-600 focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600';
    $fields = [
        'name' => ['type' => 'text', 'autocomplete' => 'name', 'required' => true, 'maxlength' => 120],
        'phone' => ['type' => 'tel', 'autocomplete' => 'tel', 'required' => true, 'maxlength' => 32],
        'email' => ['type' => 'email', 'autocomplete' => 'email', 'required' => false, 'maxlength' => 190],
    ];
@endphp

<section id="careers-apply" class="mt-10 grid gap-6 rounded-box-lg border border-line bg-bg-soft p-6 sm:p-8 lg:grid-cols-[1fr_1.2fr]" aria-labelledby="careers-apply-title" data-careers-apply>
    <div>
        <h2 id="careers-apply-title" class="text-xl font-bold text-ink sm:text-2xl">{{ $heading }}</h2>
        <p class="mt-2 text-ink-2">{{ __('careers.site.how_to_apply') }}</p>
    </div>

    <div class="rounded-box-lg bg-white p-5 shadow-card sm:p-7">
        <div role="status" aria-live="polite" aria-atomic="true">
            @if ($sent)
                <div class="flex flex-col items-start gap-3 py-4" data-careers-sent>
                    <span class="grid size-12 place-items-center rounded-full bg-brand-50 text-brand-600">
                        <x-site.icon name="check" size="size-6" />
                    </span>
                    <p class="font-semibold text-ink">{{ __('careers.site.form.sent') }}</p>
                </div>
            @endif
        </div>

        @if (! $form['enabled'])
            <p class="text-ink-2" data-careers-closed>{{ __('careers.site.form.closed') }}</p>
        @elseif (! $sent)
            <form method="post" action="{{ $form['action'] }}" @if ($form['cv']) enctype="multipart/form-data" @endif class="relative grid gap-3.5" data-careers-form>
                @csrf
                <input type="hidden" name="form_token" value="{{ $form['token'] }}">

                <div role="alert">
                    @if ($bag->has('form'))
                        <p class="rounded-btn border border-danger/30 bg-danger/5 px-4 py-3 text-sm font-medium text-danger" data-form-error>{{ $bag->first('form') }}</p>
                    @endif
                </div>

                @foreach ($fields as $field => $options)
                    <div class="grid gap-1.5">
                        <label for="careers-{{ $field }}" class="text-sm font-semibold">
                            {{ __("careers.site.form.{$field}") }}
                            @if ($options['required'])
                                <span class="text-danger" aria-hidden="true">*</span>
                            @endif
                        </label>
                        <input
                            id="careers-{{ $field }}"
                            name="{{ $field }}"
                            type="{{ $options['type'] }}"
                            value="{{ is_string(old($field)) ? old($field) : '' }}"
                            autocomplete="{{ $options['autocomplete'] }}"
                            maxlength="{{ $options['maxlength'] }}"
                            @if ($field === 'phone') inputmode="tel" @endif
                            @if ($options['required']) required aria-required="true" @endif
                            @if ($bag->has($field)) aria-invalid="true" aria-describedby="careers-{{ $field }}-error" @endif
                            @class([$inputClass, 'border-danger' => $bag->has($field), 'border-line' => ! $bag->has($field)])
                        >
                        @if ($bag->has($field))
                            <p id="careers-{{ $field }}-error" class="text-sm text-danger">{{ $bag->first($field) }}</p>
                        @endif
                    </div>
                @endforeach

                <div class="grid gap-1.5">
                    <label for="careers-message" class="text-sm font-semibold">{{ __('careers.site.form.message') }}</label>
                    <textarea
                        id="careers-message"
                        name="message"
                        rows="4"
                        maxlength="2000"
                        @if ($bag->has('message')) aria-invalid="true" aria-describedby="careers-message-error" @endif
                        @class([str_replace('h-12', 'min-h-28 py-3', $inputClass), 'border-danger' => $bag->has('message'), 'border-line' => ! $bag->has('message')])
                    >{{ is_string(old('message')) ? old('message') : '' }}</textarea>
                    @if ($bag->has('message'))
                        <p id="careers-message-error" class="text-sm text-danger">{{ $bag->first('message') }}</p>
                    @endif
                </div>

                @if ($form['cv'])
                    <div class="grid gap-1.5" data-careers-cv>
                        <label for="careers-cv" class="text-sm font-semibold">{{ __('careers.site.form.cv') }}</label>
                        <input
                            id="careers-cv"
                            name="cv"
                            type="file"
                            accept="application/pdf,.pdf"
                            aria-describedby="careers-cv-hint{{ $bag->has('cv') ? ' careers-cv-error' : '' }}"
                            @if ($bag->has('cv')) aria-invalid="true" @endif
                            class="block w-full text-sm text-ink-2 file:mr-3 file:h-10 file:cursor-pointer file:rounded-btn file:border-0 file:bg-brand-50 file:px-4 file:font-semibold file:text-brand-700 hover:file:bg-brand-100"
                        >
                        <p id="careers-cv-hint" class="text-xs text-ink-2">{{ __('careers.site.form.cv_hint') }}</p>
                        @if ($bag->has('cv'))
                            <p id="careers-cv-error" class="text-sm text-danger">{{ $bag->first('cv') }}</p>
                        @endif
                    </div>
                @endif

                {{-- Honeypot: off-screen and skipped by keyboard and screen readers. --}}
                <div class="absolute -left-[10000px] size-px overflow-hidden" aria-hidden="true">
                    <input type="text" id="careers-fax-ext" name="fax_ext" value="" tabindex="-1" autocomplete="off">
                </div>

                <button type="submit" class="mt-1 inline-flex h-[54px] w-full items-center justify-center gap-2 rounded-btn bg-brand-600 px-[30px] text-base font-semibold text-white transition-colors hover:bg-brand-700">
                    {{ __('careers.site.form.submit') }}
                </button>

                <p class="text-xs text-ink-2" data-careers-privacy>{{ $form['cv'] ? __('careers.site.form.privacy_cv') : __('careers.site.form.privacy') }}</p>
            </form>
        @endif
    </div>
</section>
