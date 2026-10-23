{{--
    Static page (App\Livewire\Site\Pages\Show), in the catalog's visual language:
    breadcrumbs, page head, text. default: optional cover image; contacts: company
    contacts (Settings → Company), requisites (legal name, STIR/INN and legal
    address, only the filled-in ones), the map behind a "Show map" click (no request to
    the map provider before it) and the lead form; legal: "Updated" date.
--}}
@inject('company', \App\Support\CompanySettings::class)

@php
    $proseClass = 'text-ink-2 [&_a]:font-medium [&_a]:text-brand-600 [&_a]:underline [&_h2]:mt-8 [&_h2]:mb-3 [&_h2]:text-xl [&_h2]:font-bold [&_h2]:text-ink [&_h3]:mt-6 [&_h3]:mb-2.5 [&_h3]:text-lg [&_h3]:font-bold [&_h3]:text-ink [&_li]:mb-2 [&_ol]:mb-4 [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:mb-4 [&_strong]:text-ink [&_ul]:mb-4 [&_ul]:list-disc [&_ul]:pl-5 [&_li]:marker:text-brand [&_table]:mb-4 [&_table]:w-full [&_td]:border [&_td]:border-line [&_td]:px-3 [&_td]:py-2 [&_th]:border [&_th]:border-line [&_th]:bg-bg-soft [&_th]:px-3 [&_th]:py-2 [&_th]:text-left';
    $hasBody = trim(strip_tags($body)) !== '';
    $isContacts = $template === \App\Enums\PageTemplate::Contacts;
    $mapEmbed = $isContacts ? $company->mapEmbedUrl() : '';
    $requisites = $isContacts ? array_filter(array_intersect_key($company->legal(), array_flip(['legal_name', 'inn', 'legal_address'])), fn (string $value): bool => $value !== '') : [];
@endphp

<div class="site-container" data-page-template="{{ $template->value }}">
    @push('head')
        @foreach ($jsonLd as $block)
            <script type="application/ld+json">{!! $block !!}</script>
        @endforeach
    @endpush

    <x-site.breadcrumbs :items="$breadcrumbs" />

    <article class="max-w-[900px]">
        <header class="mb-6">
            <h1 class="text-[26px] leading-[1.15] font-bold tracking-[-0.025em] sm:text-[34px]">{{ $title }}</h1>
            @if ($updatedAt)
                <p class="mt-2 text-sm text-ink-3" data-page-updated>
                    {{ __('site.pages.updated', ['date' => \App\Support\DateFormat::iso($updatedAt, 'D MMMM YYYY')]) }}
                </p>
            @endif
        </header>

        @if ($cover && ! $isContacts)
            <img src="{{ $cover }}" alt="{{ $coverAlt }}" width="1200" height="675" class="mb-8 aspect-video w-full rounded-box-lg object-cover" fetchpriority="high" data-page-cover>
        @endif

        @if ($hasBody)
            <div class="{{ $proseClass }}" data-page-body>{!! $body !!}</div>
        @endif
    </article>

    @if ($isContacts)
        <div class="mt-8 grid gap-6 md:grid-cols-2 md:gap-8" data-contacts>
            <section class="rounded-box-lg border border-line bg-white p-5 sm:p-7" aria-labelledby="contacts-title">
                <h2 id="contacts-title" class="mb-4 text-xl font-bold">{{ __('site.pages.contacts.title') }}</h2>
                <address class="grid gap-4 not-italic">
                    @if ($company->sitePhones() !== [])
                        <div>
                            <h3 class="mb-1.5 text-sm font-semibold text-ink-3">{{ __('site.contacts.phones') }}</h3>
                            <ul class="grid gap-1.5">
                                @foreach ($company->sitePhones() as $phone)
                                    <li data-site-phone="{{ $phone['label']->value }}">
                                        <a href="tel:{{ $phone['number'] }}" class="font-semibold text-ink hover:text-brand-600">{{ $phone['display'] }}</a>
                                        <span class="text-sm text-ink-3">· {{ $phone['label']->label() }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    @if ($company->email() !== '')
                        <p class="flex items-center gap-2"><x-site.icon name="mail" size="size-5" class="text-brand-600" /><a href="mailto:{{ $company->email() }}" class="hover:text-brand-600">{{ $company->email() }}</a></p>
                    @endif
                    @if ($company->telegramUrl() !== '')
                        <p class="flex items-center gap-2"><x-site.icon name="telegram" size="size-5" class="text-brand-600" /><a href="{{ $company->telegramUrl() }}" target="_blank" rel="noopener noreferrer" class="hover:text-brand-600">{{ preg_replace('#^https?://#', '', rtrim($company->telegramUrl(), '/')) }}</a></p>
                    @endif
                    @if ($company->address() !== '')
                        <p class="flex items-start gap-2" data-contacts-address><x-site.icon name="pin" size="size-5" class="mt-0.5 shrink-0 text-brand-600" /><span>{{ $company->address() }}@if ($company->city() !== '' && mb_stripos($company->address(), $company->city()) === false), {{ $company->city() }}@endif</span></p>
                    @endif
                    @if ($company->workingHours() !== '')
                        <p class="flex items-center gap-2"><x-site.icon name="clock" size="size-5" class="text-brand-600" /><span><span class="sr-only">{{ __('site.contacts.hours') }}: </span>{{ $company->workingHours() }}</span></p>
                    @endif
                </address>
                <x-site.social-links :links="$company->social()" class="mt-5" />

                @if ($requisites !== [])
                    <div class="mt-6 border-t border-line pt-5" data-requisites>
                        <h3 class="mb-2.5 text-sm font-semibold text-ink-3">{{ __('site.pages.contacts.requisites') }}</h3>
                        <dl class="grid gap-2 text-sm">
                            @foreach (['legal_name', 'inn', 'legal_address'] as $key)
                                @if (isset($requisites[$key]))
                                    <div class="grid gap-0.5 sm:grid-cols-[10rem_1fr] sm:gap-3" data-requisite="{{ $key }}">
                                        <dt class="text-ink-3">{{ __('site.pages.contacts.'.$key) }}</dt>
                                        <dd class="text-ink">{{ $requisites[$key] }}</dd>
                                    </div>
                                @endif
                            @endforeach
                        </dl>
                    </div>
                @endif

                @if ($mapEmbed !== '')
                    <div class="mt-6" x-data="{ map: false }" data-map-facade>
                        <div x-show="! map" class="grid aspect-[4/3] place-items-center rounded-box border border-dashed border-line bg-bg-soft p-6 text-center">
                            <div>
                                <x-site.icon name="pin" size="size-8" class="mx-auto mb-3 text-brand-600" />
                                <p class="mb-4 text-sm text-ink-2">{{ __('site.pages.contacts.map_notice') }}</p>
                                <button type="button" x-on:click="map = true" class="inline-flex h-11 items-center gap-2 rounded-btn bg-brand-600 px-5 font-semibold text-white hover:bg-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600" data-show-map>
                                    {{ __('site.pages.contacts.show_map') }}
                                </button>
                            </div>
                        </div>
                        <template x-if="map">
                            <iframe src="{{ $mapEmbed }}" title="{{ __('site.pages.contacts.map_title') }}" class="aspect-[4/3] w-full rounded-box border border-line" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
                        </template>
                        @if ($company->mapUrl() !== '')
                            <a href="{{ $company->mapUrl() }}" target="_blank" rel="noopener noreferrer" class="mt-2 inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 hover:underline">{{ __('site.contacts.map') }}</a>
                        @endif
                    </div>
                @endif
            </section>

            <section id="lead" class="scroll-mt-28" aria-labelledby="contacts-lead-title">
                <h2 id="contacts-lead-title" class="mb-2 text-xl font-bold">{{ __('site.pages.contacts.form_title') }}</h2>
                <p class="mb-4 text-ink-2">{{ __('site.pages.contacts.form_text') }}</p>
                <livewire:site.lead-form />
            </section>
        </div>
    @endif

    @if ($faq !== [])
        <x-site.faq class="mt-12 md:mt-[72px]" id="page-faq-title" :items="$faq" :title="__('site.faq.title')" />
    @endif
</div>
