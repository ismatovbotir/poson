{{--
    Dark top bar. Below 600px only the phone and the language switcher are shown
    (fixes the template's overflowing right side on mobile).
--}}
@inject('company', \App\Support\CompanySettings::class)

<div class="bg-ink text-[13px] text-ink-soft">
    <div class="site-container flex h-[38px] items-center justify-between gap-4">
        <div class="hidden items-center gap-[22px] sm:flex">
            <span>{{ $company->city() }}</span>
            <span class="hidden lg:inline">{{ $company->workingHours() }}</span>
            <a href="{{ \App\Support\Site\Navigation::page('delivery-payment') }}" class="hidden hover:text-white focus-visible:outline-white md:inline">{{ __('site.topbar.delivery_payment') }}</a>
        </div>

        <div class="flex w-full items-center justify-between gap-3 sm:w-auto sm:justify-end sm:gap-[22px]">
            @if ($company->telegramUrl() !== '')
                <a href="{{ $company->telegramUrl() }}" target="_blank" rel="noopener" class="hidden hover:text-white focus-visible:outline-white sm:inline">{{ __('site.topbar.telegram') }}</a>
            @endif
            <a href="{{ \App\Support\Site\Navigation::page('b2b') }}" class="hidden hover:text-white focus-visible:outline-white lg:inline">{{ __('site.topbar.b2b') }}</a>
            <a href="tel:{{ $company->phone() }}" class="font-bold whitespace-nowrap text-white focus-visible:outline-white">
                <span class="sr-only">{{ __('site.topbar.call_us') }}:</span>
                {{ $company->phoneDisplay() }}
            </a>
            <x-site.language-switcher variant="dark" />
        </div>
    </div>
</div>
