@php
    $t = 'chatbot_admin.settings';
    $locales = config('app.supported_locales');
@endphp

<form wire:submit="save" novalidate data-chatbot-settings>
    <x-admin.page-header :title="__($t.'.title')" :subtitle="__($t.'.subtitle')">
        <x-slot:breadcrumbs>{{ __('menu.groups.chatbot') }} <span class="text-slate-300">/</span> {{ __($t.'.title') }}</x-slot:breadcrumbs>
        <x-slot:actions>
            <div class="hidden gap-2 lg:flex">
                <x-admin.button type="submit" icon="check" loading="save">{{ __('admin.common.save') }}</x-admin.button>
            </div>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mt-5 max-w-4xl space-y-4 pb-24 lg:space-y-6">

        {{-- Channel switches: Admin only, privacy gate (CB-13) --}}
        <x-admin.card :title="__($t.'.channels')" :description="__($t.'.channels_help')" data-channels>
            <div class="space-y-4">
                @if (! $privacyReady)
                    <p class="flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2.5 text-sm text-amber-800" role="note" data-privacy-gate>
                        <x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.privacy_gate') }}
                    </p>
                @endif

                @foreach (['telegram' => $paused['telegram'], 'web' => $paused['web']] as $channelKey => $isPaused)
                    @if ($isPaused)
                        <p class="flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2.5 text-sm text-amber-800" role="status" data-paused="{{ $channelKey }}">
                            <x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" /><span><strong>{{ __($t.'.'.$channelKey.'_enabled') }}:</strong> {{ __($t.'.paused') }}</span>
                        </p>
                    @endif
                @endforeach

                @if ($isAdmin)
                    <x-admin.toggle wire:model="form.telegram_enabled" :label="__($t.'.telegram_enabled')" :description="__($t.'.telegram_enabled_hint')" />
                    @if ($webReady)
                        <x-admin.toggle wire:model="form.web_enabled" :label="__($t.'.web_enabled')" :description="__($t.'.web_enabled_hint')" />
                    @else
                        <p class="text-sm text-slate-500" data-web-not-ready>{{ __($t.'.web_not_ready') }}</p>
                    @endif
                @else
                    <dl class="grid gap-3 text-sm sm:grid-cols-2" data-channels-readonly>
                        <div class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2.5">
                            <dt class="font-semibold">{{ __($t.'.telegram_enabled') }}</dt>
                            <dd><x-admin.badge :color="$form->telegram_enabled ? 'brand' : 'muted'" pill dot>{{ $form->telegram_enabled ? __($t.'.on') : __($t.'.off') }}</x-admin.badge></dd>
                        </div>
                        <div class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2.5">
                            <dt class="font-semibold">{{ __($t.'.web_enabled') }}</dt>
                            <dd><x-admin.badge :color="$form->web_enabled ? 'brand' : 'muted'" pill dot>{{ $form->web_enabled ? __($t.'.on') : __($t.'.off') }}</x-admin.badge></dd>
                        </div>
                    </dl>
                    <p class="text-xs text-slate-500">{{ __($t.'.admin_only') }}</p>
                @endif
            </div>
        </x-admin.card>

        <p class="flex items-start gap-2 rounded-lg bg-slate-50 px-3 py-2.5 text-sm text-slate-600" role="note" data-live-note>
            <x-admin.icon name="info" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.live_note') }}
        </p>

        {{-- Texts --}}
        <x-admin.card :title="__($t.'.texts')" :description="__($t.'.texts_help', ['max' => \App\Support\Chat\ChatSettings::TEXT_MAX])">
            <x-admin.locale-tabs :error-keys="['form.texts.greeting', 'form.texts.fallback', 'form.texts.after_hours', 'form.texts.consent']" :label="__('admin.form.content_language')">
                @foreach ($locales as $locale)
                    <x-admin.locale-panel :locale="$locale">
                        @foreach (\App\Support\Chat\ChatSettings::TEXT_KEYS as $name)
                            <x-admin.textarea wire:model="form.texts.{{ $name }}.{{ $locale }}" :label="__($t.'.text_'.$name)" rows="3" maxlength="{{ \App\Support\Chat\ChatSettings::TEXT_MAX }}" :hint="__($t.'.text_'.$name.'_hint')" />
                        @endforeach
                    </x-admin.locale-panel>
                @endforeach
            </x-admin.locale-tabs>
        </x-admin.card>

        {{-- Working hours --}}
        <x-admin.card :title="__($t.'.hours')" :description="__($t.'.hours_help')">
            <x-admin.toggle wire:model.live="form.use_company_hours" :label="__($t.'.use_company_hours')" :description="__($t.'.use_company_hours_hint')" />

            @unless ($form->use_company_hours)
                <div class="mt-5 space-y-2" data-hours>
                    @foreach ($days as $day)
                        <div wire:key="hours-{{ $day }}" class="grid grid-cols-12 items-center gap-3">
                            <div class="col-span-5 sm:col-span-3"><x-admin.toggle wire:model="form.hours.{{ $day }}.open" :label="__('chatbot.days.'.$day)" /></div>
                            <x-admin.input wire:model="form.hours.{{ $day }}.from" type="time" :label="null" aria-label="{{ __($t.'.from') }}" class="col-span-3 sm:col-span-2" />
                            <x-admin.input wire:model="form.hours.{{ $day }}.to" type="time" :label="null" aria-label="{{ __($t.'.to') }}" class="col-span-3 sm:col-span-2" />
                            @error('form.hours.'.$day)<p class="col-span-12 text-xs text-danger">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                    <p class="text-xs text-slate-500">{{ __($t.'.timezone', ['timezone' => 'Asia/Tashkent']) }}</p>
                </div>
            @endunless
        </x-admin.card>

        {{-- Hand-off --}}
        <x-admin.card :title="__($t.'.handoff')" :description="__($t.'.handoff_help')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-admin.select wire:model="form.handoff_target" :label="__($t.'.handoff_target')" :options="['sales' => __($t.'.target_sales'), 'service' => __($t.'.target_service'), 'any' => __($t.'.target_any')]" />
                <x-admin.input wire:model="form.handoff_ttl_hours" type="number" min="1" max="72" :label="__($t.'.handoff_ttl')" :hint="__($t.'.handoff_ttl_hint')" />
            </div>
        </x-admin.card>

        {{-- Widget --}}
        <x-admin.card :title="__($t.'.widget')" :description="__($t.'.widget_help')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-admin.select wire:model="form.widget_position" :label="__($t.'.widget_position')" :options="['right' => __($t.'.position_right'), 'left' => __($t.'.position_left')]" />
                <x-admin.select wire:model="form.widget_pages" :label="__($t.'.widget_pages')" :options="['all' => __($t.'.pages_all'), 'no_cart' => __($t.'.pages_no_cart')]" :hint="__($t.'.widget_pages_hint')" />
                <x-admin.input wire:model="form.products_shown" type="number" min="3" max="8" :label="__($t.'.products_shown')" :hint="__($t.'.products_shown_hint')" />
                <div class="self-end"><x-admin.toggle wire:model="form.widget_mobile" :label="__($t.'.widget_mobile')" /></div>
            </div>
        </x-admin.card>

        {{-- Read-only limits --}}
        <x-admin.card :title="__($t.'.limits')" :description="__($t.'.limits_help')">
            <dl class="grid gap-2 text-sm sm:grid-cols-2" data-limits>
                <div class="flex justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2"><dt class="text-slate-600">{{ __($t.'.limit_unmatched_chat') }}</dt><dd class="font-semibold tabular-nums">{{ config('chatbot.unmatched.per_day_chat') }}</dd></div>
                <div class="flex justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2"><dt class="text-slate-600">{{ __($t.'.limit_unmatched_all') }}</dt><dd class="font-semibold tabular-nums">{{ config('chatbot.unmatched.per_day_global') }}</dd></div>
                <div class="flex justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2"><dt class="text-slate-600">{{ __($t.'.limit_min_score') }}</dt><dd class="font-semibold tabular-nums">{{ config('chatbot.min_score') }}</dd></div>
                <div class="flex justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2"><dt class="text-slate-600">{{ __($t.'.limit_rules') }}</dt><dd class="font-semibold tabular-nums">{{ config('chatbot.max_rules') }}</dd></div>
            </dl>
        </x-admin.card>
    </div>

    <x-admin.sticky-save-bar />
</form>
