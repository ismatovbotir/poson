{{--
    /o/{ulid} for an unknown, TEST, renewed or expired link (security condition 28):
    one neutral page for every case, so they can't be told apart.
--}}
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('orders.public.missing_title') }}</title>
    @fonts
    @vite(['resources/css/app.css'])
</head>
<body class="bg-slate-50 text-slate-900 antialiased">
    <main class="mx-auto grid min-h-screen max-w-lg place-items-center px-4">
        <div class="rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm" data-order-missing>
            <h1 class="text-xl font-bold">{{ __('orders.public.missing_title') }}</h1>
            <p class="mt-2 text-sm text-slate-600">{{ __('orders.public.missing_text') }}</p>
            @if ($phone)
                <p class="mt-4 text-lg font-semibold">{{ $phone }}</p>
            @endif
        </div>
    </main>
</body>
</html>
