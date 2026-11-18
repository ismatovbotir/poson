{{--
    The repair status page /r/{token} (plan 35 I3), RepairStatusController. Standalone and printable:
    no site layout, session, csrf, Livewire, chat widget, analytics or third-party asset (P35-10).
    Receives only the RepairPublicStatus DTO ($status) and company contacts: never the model.
--}}
@php
    /** @var \App\Support\Repairs\RepairPublicStatus $status */
    $ready = $status->isReady;
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('repairs.public.title', ['number' => $status->number]) }} · {{ $company['name'] }}</title>
    @fonts
    @vite(['resources/css/app.css'])
</head>
<body class="bg-slate-50 text-slate-900 antialiased">
    <main class="mx-auto max-w-xl px-4 py-6 sm:py-10">
        <nav class="mb-4 flex gap-1 text-sm" aria-label="{{ __('repairs.public.language') }}">
            @foreach ($switchUrls as $lang => $url)
                <a href="{{ $url }}" lang="{{ $lang }}" aria-label="{{ __('admin.locale.'.$lang, [], $lang) }}" @if ($lang === $locale) aria-current="true" @endif @class(['rounded-md px-2.5 py-1 font-semibold', 'bg-slate-900 text-white' => $lang === $locale, 'text-slate-600 hover:bg-slate-200' => $lang !== $locale])>{{ strtoupper($lang) }}</a>
            @endforeach
        </nav>

        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8">
            <header class="border-b border-slate-100 pb-5">
                @if ($logo)
                    <img src="{{ $logo }}" alt="{{ $company['name'] }}" class="max-h-14 max-w-48">
                @else
                    <p class="text-xl font-extrabold">{{ $company['name'] }}</p>
                @endif
            </header>

            <p class="mt-5 text-sm text-slate-500">{{ __('repairs.public.heading') }}</p>
            <h1 class="text-2xl font-bold tracking-tight" data-number>{{ $status->number }}</h1>
            @if ($status->deviceLine !== '')
                <p class="mt-1 text-slate-700" data-device>{{ $status->deviceLine }}</p>
            @endif

            <div class="mt-5">
                <p class="text-lg font-bold" data-status>{{ $status->statusLabel }}@if ($status->statusDay) <span class="text-sm font-normal text-slate-500">{{ __('repairs.public.since', ['day' => $status->statusDay]) }}</span>@endif</p>
                @if ($status->receivedDay)
                    <p class="text-sm text-slate-500" data-received>{{ __('repairs.public.received', ['day' => $status->receivedDay]) }}</p>
                @endif
            </div>

            @if ($status->step !== null)
                <ol class="mt-5 grid grid-cols-5 gap-1" aria-label="{{ __('repairs.public.progress') }}" data-progress>
                    @foreach (__('repairs.public.steps') as $index => $label)
                        <li class="text-center text-xs" @if ($index === $status->step) aria-current="step" @endif>
                            <span @class(['block h-2 rounded-full', 'bg-emerald-500' => $index <= $status->step, 'bg-slate-200' => $index > $status->step])></span>
                            <span @class(['mt-1 block', 'font-semibold text-slate-900' => $index === $status->step, 'text-slate-500' => $index !== $status->step])>{{ $label }}</span>
                        </li>
                    @endforeach
                </ol>
            @endif

            <p class="mt-5 rounded-xl bg-slate-50 px-4 py-3 text-sm" data-message>{{ __($status->messageKey, ['day' => $status->closedDay ?? '']) }}</p>

            @if ($ready)
                <section class="mt-4 rounded-xl border border-emerald-300 bg-emerald-50 p-4" data-ready>
                    <h2 class="font-bold text-emerald-900">{{ __('repairs.public.ready_title') }}</h2>
                    <p class="mt-1 text-sm text-emerald-900">{{ __('repairs.public.ready_text') }}</p>
                    @if ($company['address'])<p class="mt-2 text-sm font-semibold">{{ $company['address'] }}</p>@endif
                    @if ($company['hours'])<p class="text-sm font-semibold">{{ $company['hours'] }}</p>@endif
                    @if ($company['phone'])<p class="mt-1 text-lg font-bold">{{ $company['phone'] }}</p>@endif
                </section>
            @endif

            <footer class="mt-6 border-t border-slate-100 pt-4 text-sm text-slate-600">
                <p class="font-semibold text-slate-900">{{ $company['name'] }} · {{ __('repairs.public.contacts') }}</p>
                @if ($company['phone'])<p>{{ __('repairs.public.phone') }}: {{ $company['phone'] }}</p>@endif
                @if ($company['address'])<p>{{ __('repairs.public.address') }}: {{ $company['address'] }}</p>@endif
                @if ($company['hours'])<p>{{ __('repairs.public.hours') }}: {{ $company['hours'] }}</p>@endif
                @if ($company['telegram'])<p><a href="{{ $company['telegram'] }}" rel="noopener noreferrer" class="font-semibold text-sky-700 underline">{{ __('repairs.public.telegram') }}</a></p>@endif
            </footer>
        </article>
    </main>
</body>
</html>
