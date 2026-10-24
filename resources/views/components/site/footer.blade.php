{{--
    Site footer. "#contacts" is the anchor inactive static pages fall back to
    (Navigation::page()); "For customers" starts with "About us". "Catalog" lists
    the top-level categories (Navigation::footerCatalog(), from CategoryTree), then Brands.
    Contacts come from Settings → Company: site phones (primary first, labelled),
    email, address with a map link, hours and social icons.
--}}
@inject('company', \App\Support\CompanySettings::class)

@php
    $telegram = $company->telegramUrl();
    $telegramLabel = $telegram !== '' ? preg_replace('#^https?://#', '', rtrim($telegram, '/')) : '';
    $linkClass = 'transition-colors hover:text-white focus-visible:outline-white';
    $mapUrl = $company->mapUrl();
@endphp

<footer id="contacts" class="mt-12 bg-ink pt-12 pb-7 text-ink-3 md:mt-[72px] md:pt-16">
    <div class="site-container">
        <div class="grid gap-10 sm:grid-cols-2 md:grid-cols-[1.4fr_repeat(3,1fr)]">
            <div>
                <x-site.logo variant="dark" class="mb-4" />
                <p class="max-w-[300px] text-sm">{{ __('site.footer.description') }}</p>
            </div>

            <nav aria-labelledby="footer-catalog">
                <h2 id="footer-catalog" class="mb-4 text-[15px] font-bold text-white">{{ __('site.footer.catalog') }}</h2>
                <ul class="grid gap-2.5 text-sm">
                    @foreach (\App\Support\Site\Navigation::footerCatalog() as $item)
                        <li><a href="{{ $item['url'] }}" class="{{ $linkClass }}">{{ $item['label'] }}</a></li>
                    @endforeach
                    <li><a href="{{ \App\Support\Site\Navigation::brands() }}" class="{{ $linkClass }}" data-footer-brands>{{ __('site.nav.brands') }}</a></li>
                </ul>
            </nav>

            <nav aria-labelledby="footer-customers">
                <h2 id="footer-customers" class="mb-4 text-[15px] font-bold text-white">{{ __('site.footer.customers') }}</h2>
                <ul class="grid gap-2.5 text-sm">
                    @foreach (\App\Support\Site\Navigation::customerPages() as $item)
                        <li><a href="{{ $item['url'] }}" class="{{ $linkClass }}">{{ $item['label'] }}</a></li>
                    @endforeach
                </ul>
            </nav>

            <div>
                <h2 class="mb-4 text-[15px] font-bold text-white">{{ __('site.footer.contacts') }}</h2>
                <address class="grid gap-2.5 text-sm not-italic">
                    @foreach ($company->sitePhones() as $phone)
                        <span class="grid" data-site-phone="{{ $phone['label']->value }}">
                            <a href="tel:{{ $phone['number'] }}" @class(['font-semibold text-white', $linkClass])>{{ $phone['display'] }}</a>
                            <span class="text-xs">{{ $phone['label']->label() }}</span>
                        </span>
                    @endforeach
                    @if ($telegram !== '')
                        <a href="{{ $telegram }}" target="_blank" rel="noopener noreferrer" class="{{ $linkClass }}">{{ $telegramLabel }}</a>
                    @endif
                    @if ($company->email() !== '')
                        <a href="mailto:{{ $company->email() }}" class="{{ $linkClass }}">{{ $company->email() }}</a>
                    @endif
                    @if ($company->address() !== '')
                        <span>{{ $company->address() }}</span>
                    @endif
                    @if ($mapUrl !== '')
                        <a href="{{ $mapUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 {{ $linkClass }}" data-map-link>
                            <x-site.icon name="pin" size="size-4" />{{ __('site.contacts.map') }}
                        </a>
                    @endif
                    @if ($company->workingHours() !== '')
                        <span>{{ $company->workingHours() }}</span>
                    @endif
                </address>
                <x-site.social-links :links="$company->social()" class="mt-4" />
            </div>
        </div>

        <div class="mt-11 flex flex-wrap justify-between gap-2.5 border-t border-ink-line pt-[22px] text-[13px]">
            <span>{{ __('site.footer.rights', ['year' => now()->year]) }}</span>
            @php($legalLinks = \App\Support\Site\Navigation::legalPages())
            @if ($legalLinks !== [])
                <span class="flex flex-wrap gap-x-2" data-legal-links>
                    @foreach ($legalLinks as $item)
                        @if (! $loop->first)
                            <span aria-hidden="true">·</span>
                        @endif
                        <a href="{{ $item['url'] }}" class="{{ $linkClass }}" data-legal-link="{{ $item['key'] }}">{{ $item['label'] }}</a>
                    @endforeach
                </span>
            @endif
        </div>
    </div>
</footer>
