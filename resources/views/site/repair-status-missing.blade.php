{{--
    /r/{token} for an unknown, malformed, rotated or expired link (plan 35, P35-11):
    one neutral page for every case. Standalone: no session, csrf, Livewire, widget or third-party asset (P35-10).
--}}
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('repairs.public.missing_title') }}</title>
    @fonts
    @vite(['resources/css/app.css'])
</head>
<body class="bg-slate-50 text-slate-900 antialiased">
    <main class="mx-auto grid min-h-screen max-w-lg place-items-center px-4">
        <div class="rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm" data-repair-missing>
            <h1 class="text-xl font-bold">{{ __('repairs.public.missing_title') }}</h1>
            <p class="mt-2 text-sm text-slate-600">{{ filled($company['phone']) ? __('repairs.public.missing_text', ['phone' => $company['phone']]) : __('repairs.public.missing_text_no_phone') }}</p>
        </div>
    </main>
</body>
</html>
