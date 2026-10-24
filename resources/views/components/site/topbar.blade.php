{{--
    Dark top bar. Below 600px only the phone and the language switcher are shown
    (fixes the template's overflowing right side on mobile). Page and news links
    live in the main nav (decision 2026-10-24), not here.
--}}
@inject('company', \App\Support\CompanySettings::class)

<div class="bg-ink text-[13px] text-ink-soft">
    <div class="site-container flex h-[38px] items-center justify-between gap-4">
        <div class="hidden items-center gap-[22px] sm:flex">
            <span>{{ $company->city() }}</span>
            <span class="hidden lg:inline">{{ $company->workingHours() }}</span>
        </div>

        <div class="flex w-full items-center justify-between gap-3 sm:w-auto sm:justify-end sm:gap-[22px]">
            @if ($company->telegramUrl() !== '')
                <a href="{{ $company->telegramUrl() }}" target="_blank" rel="noopener noreferrer" class="hidden hover:text-white focus-visible:outline-white sm:inline">{{ __('site.topbar.telegram') }}</a>
            @endif
            @if ($company->phone() !== '')
                <a href="tel:{{ $company->phone() }}" class="font-bold whitespace-nowrap text-white focus-visible:outline-white">
                    <span class="sr-only">{{ __('site.topbar.call_us') }}:</span>
                    {{ $company->phoneDisplay() }}
                </a>
            @endif
            <x-site.language-switcher variant="dark" />
        </div>
    </div>
</div>
