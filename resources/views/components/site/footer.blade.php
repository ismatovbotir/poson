{{-- Site footer. "#contacts" is the contacts anchor until a contacts page exists. --}}
@inject('company', \App\Support\CompanySettings::class)

@php
    $telegram = $company->telegramUrl();
    $telegramLabel = $telegram !== '' ? preg_replace('#^https?://#', '', rtrim($telegram, '/')) : '';
    $linkClass = 'transition-colors hover:text-white focus-visible:outline-white';
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
                    <a href="tel:{{ $company->phone() }}" class="font-semibold text-white {{ $linkClass }}">{{ $company->phoneDisplay() }}</a>
                    @if ($telegram !== '')
                        <a href="{{ $telegram }}" target="_blank" rel="noopener" class="{{ $linkClass }}">{{ $telegramLabel }}</a>
                    @endif
                    @if ($company->email() !== '')
                        <a href="mailto:{{ $company->email() }}" class="{{ $linkClass }}">{{ $company->email() }}</a>
                    @endif
                    <span>{{ $company->address() }}</span>
                    <span>{{ $company->workingHours() }}</span>
                </address>
            </div>
        </div>

        <div class="mt-11 flex flex-wrap justify-between gap-2.5 border-t border-ink-line pt-[22px] text-[13px]">
            <span>{{ __('site.footer.rights', ['year' => now()->year]) }}</span>
            <span class="flex flex-wrap gap-x-2">
                <a href="{{ \App\Support\Site\Navigation::page('privacy') }}" class="{{ $linkClass }}">{{ __('site.footer.privacy') }}</a>
                <span aria-hidden="true">·</span>
                <a href="{{ \App\Support\Site\Navigation::page('offer') }}" class="{{ $linkClass }}">{{ __('site.footer.offer') }}</a>
            </span>
        </div>
    </div>
</footer>
