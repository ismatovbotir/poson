@php
    $locales = config('app.supported_locales');
    $t = 'admin.settings.company';
    $days = array_keys(\App\Support\Settings\SettingsFormat::DAYS);
@endphp

{{-- Settings → Company (plan 04a T2). "Save" saves the open tab; the FAQ tab saves each item itself. --}}
<form wire:submit="save" novalidate>
    <x-admin.page-header :title="__($t.'.title')" :subtitle="__($t.'.subtitle')">
        @if ($hasSave)
            <x-slot:actions>
                <div class="hidden gap-2 lg:flex">
                    <x-admin.button type="submit" icon="check" loading="save">{{ __('admin.common.save') }}</x-admin.button>
                </div>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    {{-- Tabs --}}
    <nav class="scrollbar-none sticky top-16 z-20 -mx-4 mt-4 overflow-x-auto border-b border-slate-200 bg-canvas/95 px-4 backdrop-blur sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8" aria-label="{{ __($t.'.tabs.label') }}">
        <div class="flex gap-5" role="tablist">
            @foreach ($tabs as $key)
                <button
                    type="button"
                    role="tab"
                    wire:key="tab-{{ $key }}"
                    wire:click="$set('tab', '{{ $key }}')"
                    aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    data-settings-tab="{{ $key }}"
                    @class([
                        '-mb-px flex items-center gap-1.5 whitespace-nowrap border-b-2 py-3 text-sm font-semibold',
                        'border-brand-600 text-brand-700' => $tab === $key,
                        'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' => $tab !== $key,
                    ])
                >
                    {{ __($t.'.tabs.'.$key) }}
                    @if (in_array($key, ['legal', 'tracking'], true))
                        <x-admin.icon name="lock" size="size-3.5" class="text-slate-400" />
                    @endif
                    @if ($errors->has(\Illuminate\Support\Str::camel($key).'.*'))
                        <span class="size-1.5 rounded-full bg-danger" aria-hidden="true"></span>
                        <span class="sr-only">({{ __('admin.form.locale_has_errors') }})</span>
                    @endif
                </button>
            @endforeach
        </div>
    </nav>

    <div class="mt-5 max-w-4xl space-y-4 pb-24 lg:space-y-6" role="tabpanel">
        @if ($tab === 'contacts')
            <x-admin.card :title="__($t.'.sections.company')">
                <x-admin.locale-tabs :error-keys="['contacts.name', 'contacts.address', 'contacts.city', 'contacts.working_hours']" :label="__('admin.form.content_language')">
                    @foreach ($locales as $locale)
                        <x-admin.locale-panel :locale="$locale">
                            <x-admin.input wire:model="contacts.name.{{ $locale }}" :label="__($t.'.fields.name')" :required="$locale === 'uz'" maxlength="100" :hint="__($t.'.hints.name')" />
                            <x-admin.input wire:model="contacts.address.{{ $locale }}" :label="__($t.'.fields.address')" :required="$locale === 'uz'" maxlength="255" />
                            <x-admin.input wire:model="contacts.city.{{ $locale }}" :label="__($t.'.fields.city')" :required="$locale === 'uz'" maxlength="100" />
                            <x-admin.input wire:model="contacts.working_hours.{{ $locale }}" :label="__($t.'.fields.working_hours')" :required="$locale === 'uz'" maxlength="100" :hint="__($t.'.hints.working_hours')" />
                        </x-admin.locale-panel>
                    @endforeach
                </x-admin.locale-tabs>

                <x-admin.input wire:model="contacts.email" type="email" inputmode="email" class="mt-5" :label="__($t.'.fields.email')" required maxlength="150" />
            </x-admin.card>

            <x-admin.card :title="__($t.'.fields.phones')" :description="__($t.'.hints.phones')">
                <div class="space-y-3">
                    @foreach ($contacts->phones as $index => $phone)
                        <div wire:key="phone-{{ $index }}" class="grid gap-3 rounded-xl border border-slate-200 p-3 sm:grid-cols-[10rem_1fr_auto]" data-phone-row>
                            <x-admin.select wire:model="contacts.phones.{{ $index }}.label" :label="__($t.'.fields.phone_label')" :options="$phoneLabels" />
                            <x-admin.input wire:model="contacts.phones.{{ $index }}.number" type="tel" inputmode="tel" :label="__($t.'.fields.phone_number')" placeholder="+998 90 123 45 67" maxlength="30" />
                            <div class="flex items-end gap-1">
                                @if (count($contacts->phones) > 1)
                                    <button type="button" wire:click="removePhone({{ $index }})" class="grid size-11 place-items-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-danger lg:size-10" aria-label="{{ __($t.'.remove_phone') }}"><x-admin.icon name="trash" size="size-4" /></button>
                                @endif
                            </div>
                            <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm sm:col-span-3">
                                <label class="flex items-center gap-2">
                                    <input type="radio" wire:model="contacts.primary" value="{{ $index }}" class="size-4 accent-brand-600">
                                    {{ __($t.'.fields.primary_phone') }}
                                </label>
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" wire:model="contacts.phones.{{ $index }}.site" class="size-4 rounded accent-brand-600">
                                    {{ __($t.'.fields.show_on_site') }}
                                </label>
                            </div>
                        </div>
                    @endforeach
                    <x-admin.field-error name="contacts.phones" />
                    <x-admin.field-error name="contacts.primary" />
                    @if (count($contacts->phones) < \App\Livewire\Forms\Admin\Settings\ContactsForm::MAX_PHONES)
                        <x-admin.button variant="secondary" size="sm" icon="plus" wire:click="addPhone">{{ __($t.'.add_phone') }}</x-admin.button>
                    @endif
                </div>
            </x-admin.card>

            <x-admin.card :title="__($t.'.sections.location')" :description="__($t.'.hints.coordinates')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-admin.input wire:model.blur="contacts.lat" inputmode="decimal" :label="__($t.'.fields.lat')" placeholder="41.311081" maxlength="14" />
                    <x-admin.input wire:model.blur="contacts.lng" inputmode="decimal" :label="__($t.'.fields.lng')" placeholder="69.240562" maxlength="14" />
                </div>
                @if ($mapPreviewUrl !== '')
                    <a href="{{ $mapPreviewUrl }}" target="_blank" rel="noopener noreferrer" class="mt-3 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 hover:underline" data-map-preview>
                        <x-admin.icon name="external" size="size-4" />{{ __($t.'.map_preview') }}
                    </a>
                @endif
            </x-admin.card>

            <x-admin.card :title="__($t.'.fields.opening_hours')" :description="__($t.'.hints.opening_hours')">
                <div class="space-y-3">
                    @foreach ($contacts->opening_hours as $index => $row)
                        <div wire:key="hours-{{ $index }}" class="rounded-xl border border-slate-200 p-3">
                            <fieldset>
                                <legend class="text-sm font-semibold">{{ __($t.'.fields.days') }}</legend>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($days as $day)
                                        <label class="flex h-9 cursor-pointer items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 text-sm has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                                            <input type="checkbox" wire:model="contacts.opening_hours.{{ $index }}.days" value="{{ $day }}" class="size-4 accent-brand-600">
                                            {{ __($t.'.days.'.$day) }}
                                        </label>
                                    @endforeach
                                </div>
                                <x-admin.field-error name="contacts.opening_hours.{{ $index }}.days" />
                            </fieldset>
                            <div class="mt-3 grid grid-cols-[1fr_1fr_auto] items-end gap-3">
                                <x-admin.input wire:model="contacts.opening_hours.{{ $index }}.opens" type="time" :label="__($t.'.fields.opens')" />
                                <x-admin.input wire:model="contacts.opening_hours.{{ $index }}.closes" type="time" :label="__($t.'.fields.closes')" />
                                <button type="button" wire:click="removeOpeningHours({{ $index }})" class="grid size-11 place-items-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-danger lg:size-10" aria-label="{{ __($t.'.remove_hours') }}"><x-admin.icon name="trash" size="size-4" /></button>
                            </div>
                        </div>
                    @endforeach
                    @if (count($contacts->opening_hours) < \App\Livewire\Forms\Admin\Settings\ContactsForm::MAX_OPENING_HOURS)
                        <x-admin.button variant="secondary" size="sm" icon="plus" wire:click="addOpeningHours">{{ __($t.'.add_hours') }}</x-admin.button>
                    @endif
                </div>
            </x-admin.card>
        @endif

        @if ($tab === 'legal')
            <x-admin.card :title="__($t.'.tabs.legal')" :description="__($t.'.hints.legal')">
                <x-admin.locale-tabs :error-keys="['legal.legal_name', 'legal.legal_address']" :label="__('admin.form.content_language')">
                    @foreach ($locales as $locale)
                        <x-admin.locale-panel :locale="$locale">
                            <x-admin.input wire:model="legal.legal_name.{{ $locale }}" :label="__($t.'.fields.legal_name')" maxlength="255" :hint="__($t.'.hints.legal_name')" />
                            <x-admin.input wire:model="legal.legal_address.{{ $locale }}" :label="__($t.'.fields.legal_address')" maxlength="255" />
                        </x-admin.locale-panel>
                    @endforeach
                </x-admin.locale-tabs>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <x-admin.input wire:model="legal.inn" inputmode="numeric" :label="__($t.'.fields.inn')" maxlength="20" :hint="__($t.'.hints.inn')" />
                    <x-admin.input wire:model="legal.vat_code" inputmode="numeric" :label="__($t.'.fields.vat_code')" maxlength="20" :hint="__($t.'.hints.vat_code')" />
                    <x-admin.input wire:model="legal.bank" class="sm:col-span-2" :label="__($t.'.fields.bank')" maxlength="255" />
                    <x-admin.input wire:model="legal.mfo" inputmode="numeric" :label="__($t.'.fields.mfo')" maxlength="10" :hint="__($t.'.hints.mfo')" />
                    <x-admin.input wire:model="legal.account" inputmode="numeric" :label="__($t.'.fields.account')" maxlength="30" :hint="__($t.'.hints.account')" />
                    <x-admin.input wire:model="legal.oked" inputmode="numeric" :label="__($t.'.fields.oked')" maxlength="10" :hint="__($t.'.hints.oked')" />
                    <x-admin.input wire:model="legal.director" :label="__($t.'.fields.director')" maxlength="255" />
                </div>
            </x-admin.card>

            {{-- Quote PDFs only (plan 06): private PNG files, never shown on the site. --}}
            <div class="grid gap-4 sm:grid-cols-2 lg:gap-6" data-legal-images>
                <x-admin.card :title="__($t.'.fields.stamp')">
                    <x-admin.image-upload model="legal.stamp" :file="$legal->stamp" :current-url="$legalImages['stamp']" remove-action="removeLegalImage('stamp')" :hint="__($t.'.hints.stamp')" />
                </x-admin.card>
                <x-admin.card :title="__($t.'.fields.signature')">
                    <x-admin.image-upload model="legal.signature" :file="$legal->signature" :current-url="$legalImages['signature']" remove-action="removeLegalImage('signature')" :hint="__($t.'.hints.signature')" />
                </x-admin.card>
            </div>
        @endif

        @if ($tab === 'social')
            <x-admin.card :title="__($t.'.tabs.social')" :description="__($t.'.hints.social')">
                <div class="space-y-3">
                    @forelse ($social->links as $index => $link)
                        <div wire:key="social-{{ $index }}" class="grid items-start gap-3 sm:grid-cols-[11rem_1fr_auto]" data-social-row>
                            <x-admin.select wire:model.live="social.links.{{ $index }}.network" :label="__($t.'.fields.network')">
                                @foreach ($networks as $network)
                                    <option value="{{ $network->value }}">{{ $network->label() }}</option>
                                @endforeach
                            </x-admin.select>
                            <x-admin.input wire:model="social.links.{{ $index }}.url" type="url" inputmode="url" :label="__($t.'.fields.url')" :placeholder="\App\Enums\SocialNetwork::tryFrom($link['network'] ?? '')?->example() ?? 'https://'" maxlength="255" />
                            <div class="flex items-end sm:pt-7">
                                <button type="button" wire:click="removeSocialLink({{ $index }})" class="grid size-11 place-items-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-danger lg:size-10" aria-label="{{ __($t.'.remove_link') }}"><x-admin.icon name="trash" size="size-4" /></button>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">{{ __($t.'.no_links') }}</p>
                    @endforelse
                    @if (count($social->links) < \App\Livewire\Forms\Admin\Settings\SocialForm::MAX_LINKS)
                        <x-admin.button variant="secondary" size="sm" icon="plus" wire:click="addSocialLink">{{ __($t.'.add_link') }}</x-admin.button>
                    @endif
                </div>
            </x-admin.card>
        @endif

        @if ($tab === 'hero')
            <x-admin.card :title="__($t.'.tabs.hero')" :description="__($t.'.hints.hero')">
                <x-admin.locale-tabs :error-keys="['hero.stats.0.value', 'hero.stats.0.label', 'hero.stats.1.value', 'hero.stats.1.label', 'hero.stats.2.value', 'hero.stats.2.label']" :label="__('admin.form.content_language')">
                    @foreach ($locales as $locale)
                        <x-admin.locale-panel :locale="$locale">
                            @foreach ($hero->stats as $index => $stat)
                                <div wire:key="stat-{{ $locale }}-{{ $index }}" class="grid gap-3 sm:grid-cols-2">
                                    <x-admin.input wire:model="hero.stats.{{ $index }}.value.{{ $locale }}" :label="__($t.'.fields.stat_value', ['number' => $index + 1])" :required="$locale === 'uz'" maxlength="40" />
                                    <x-admin.input wire:model="hero.stats.{{ $index }}.label.{{ $locale }}" :label="__($t.'.fields.stat_label', ['number' => $index + 1])" :required="$locale === 'uz'" maxlength="80" />
                                </div>
                            @endforeach
                        </x-admin.locale-panel>
                    @endforeach
                </x-admin.locale-tabs>
            </x-admin.card>
        @endif

        @if ($tab === 'seo')
            <x-admin.card :title="__($t.'.sections.verification')" :description="__($t.'.hints.verification')">
                <div class="space-y-4">
                    <x-admin.input wire:model="seo.google" :label="__($t.'.fields.google')" maxlength="100" autocomplete="off" spellcheck="false" :hint="__($t.'.hints.google')" />
                    <x-admin.input wire:model="seo.yandex" :label="__($t.'.fields.yandex')" maxlength="100" autocomplete="off" spellcheck="false" :hint="__($t.'.hints.yandex')" />
                    <x-admin.input wire:model="seo.bing" :label="__($t.'.fields.bing')" maxlength="100" autocomplete="off" spellcheck="false" :hint="__($t.'.hints.bing')" />
                </div>
            </x-admin.card>

            <div class="grid gap-4 sm:grid-cols-2 lg:gap-6">
                <x-admin.card :title="__($t.'.fields.logo')">
                    <x-admin.image-upload model="seo.logo" :file="$seo->logo" :current-url="$logoUrl" :processing="$logoProcessing" remove-action="removeLogo" :hint="__($t.'.hints.logo')" />
                </x-admin.card>
                <x-admin.card :title="__($t.'.fields.og_image')">
                    <x-admin.image-upload model="seo.og_image" :file="$seo->og_image" :current-url="$ogImageUrl" :processing="$ogImageProcessing" remove-action="removeOgImage" :hint="__($t.'.hints.og_image')" />
                </x-admin.card>
            </div>
        @endif

        @if ($tab === 'tracking')
            <x-admin.card :title="__($t.'.tabs.tracking')" :description="__($t.'.hints.tracking')">
                <div class="space-y-5">
                    <x-admin.toggle wire:model="tracking.enabled" :label="__($t.'.fields.tracking_enabled')" :description="__($t.'.hints.tracking_enabled')" />
                    <x-admin.input wire:model="tracking.ga4" :label="__($t.'.fields.ga4')" placeholder="G-XXXXXXXXXX" maxlength="20" autocomplete="off" spellcheck="false" :hint="__($t.'.hints.ga4')" />
                    <x-admin.input wire:model="tracking.metrika" inputmode="numeric" :label="__($t.'.fields.metrika')" placeholder="98765432" maxlength="12" autocomplete="off" :hint="__($t.'.hints.metrika')" />
                </div>
            </x-admin.card>
        @endif

        @if ($tab === 'ai_bots')
            <div class="flex gap-3 rounded-xl border border-sky-200 bg-sky-50 p-3.5 text-sm text-sky-900" role="note" data-ai-bots-note>
                <x-admin.icon name="info" class="mt-0.5 shrink-0 text-sky-600" />
                <p>
                    {{ __($t.'.ai_bots.note') }}
                    @if (\Illuminate\Support\Facades\Route::has('admin.analytics.ai'))
                        @can('analytics.view')
                            <a href="{{ route('admin.analytics.ai') }}" wire:navigate class="font-semibold underline">{{ __($t.'.ai_bots.analytics_link') }}</a>
                        @endcan
                    @endif
                </p>
            </div>

            <x-admin.card :title="__($t.'.ai_bots.presets_title')" :description="__($t.'.ai_bots.presets_help')">
                <div class="flex flex-wrap items-center gap-2" role="group" aria-label="{{ __($t.'.ai_bots.presets_title') }}">
                    @foreach (\App\Support\Site\AiBotRules::PRESETS as $preset)
                        <button
                            type="button"
                            wire:click="applyBotPreset('{{ $preset }}')"
                            aria-pressed="{{ $botPreset === $preset ? 'true' : 'false' }}"
                            data-bot-preset="{{ $preset }}"
                            @class([
                                'inline-flex h-10 items-center gap-1.5 rounded-lg border px-4 text-sm font-semibold',
                                'border-brand-600 bg-brand-50 text-brand-700' => $botPreset === $preset,
                                'border-slate-200 text-slate-700 hover:bg-slate-50' => $botPreset !== $preset,
                            ])
                        >
                            @if ($botPreset === $preset)<x-admin.icon name="check" size="size-4" />@endif
                            {{ __($t.'.ai_bots.presets.'.$preset) }}
                        </button>
                    @endforeach
                    @if ($botPreset === null)
                        <x-admin.badge color="amber" data-bot-preset-custom>{{ __($t.'.ai_bots.custom') }}</x-admin.badge>
                    @endif
                </div>
                <ul class="mt-3 space-y-1 text-xs text-slate-500">
                    @foreach (\App\Support\Site\AiBotRules::PRESETS as $preset)
                        <li><span class="font-semibold text-slate-700">{{ __($t.'.ai_bots.presets.'.$preset) }}:</span> {{ __($t.'.ai_bots.preset_hints.'.$preset) }}</li>
                    @endforeach
                </ul>
            </x-admin.card>

            <x-admin.card :title="__($t.'.ai_bots.list_title')" :description="__($t.'.ai_bots.list_help')">
                <ul class="-my-3 divide-y divide-slate-100">
                    @foreach ($aiBotList as $key => $bot)
                        <li wire:key="bot-{{ $key }}" class="flex flex-wrap items-center gap-3 py-3" data-bot="{{ $key }}">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold">{{ $bot->name }}</p>
                                <p class="text-xs text-slate-500">{{ $bot->vendor }} · <code class="font-mono">User-agent: {{ $bot->robotsToken }}</code></p>
                            </div>
                            <x-admin.badge :color="$bot->purpose->color()">{{ $bot->purpose->label() }}</x-admin.badge>
                            <x-admin.toggle wire:model.live="aiBots.bots.{{ $key }}" :id="'bot-'.$key" :label="__($t.'.ai_bots.allow')" class="w-32" />
                        </li>
                    @endforeach
                </ul>
            </x-admin.card>

            <x-admin.card :title="__($t.'.ai_bots.search_title')" :description="__($t.'.ai_bots.search_help')">
                <ul class="-my-3 divide-y divide-slate-100">
                    @foreach ($searchEngines as $key => $bot)
                        <li wire:key="search-bot-{{ $key }}" class="flex flex-wrap items-center gap-3 py-3" data-search-bot="{{ $key }}">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold">{{ $bot->name }}</p>
                                <p class="text-xs text-slate-500">{{ $bot->vendor }} · <code class="font-mono">User-agent: {{ $bot->robotsToken }}</code></p>
                            </div>
                            <x-admin.badge :color="$bot->purpose->color()">{{ $bot->purpose->label() }}</x-admin.badge>
                            <span class="inline-flex w-32 items-center gap-1.5 text-sm font-medium text-slate-500"><x-admin.icon name="lock" size="size-3.5" />{{ __($t.'.ai_bots.always_allowed') }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-admin.card>
        @endif

        @if ($tab === 'faq')
            <section class="rounded-2xl border border-slate-200 bg-white">
                <livewire:admin.faq.faq-items :key="'faq-general'" />
            </section>
        @endif
    </div>

    @if ($hasSave)
        <x-admin.sticky-save-bar />
    @endif
</form>
