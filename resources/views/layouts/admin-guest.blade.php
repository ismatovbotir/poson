@props(['title' => null])

{{--
    Admin guest layout (layouts::admin-guest): login screen, ported from
    .claude/html-templates/admin/login.html. Brand panel on lg and up.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f172a">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ? $title.' · ' : '' }}{{ __('admin.meta.app_name') }}</title>

    @fonts
    @vite(['resources/css/admin.css', 'resources/js/admin.js'])
    @livewireStyles
</head>
<body class="min-h-full bg-white font-sans">
    <x-admin.icon-sprite />

    <div class="grid min-h-dvh lg:grid-cols-2">
        <div class="relative hidden overflow-hidden bg-slate-900 lg:block">
            <div class="absolute inset-0 opacity-[.07]" style="background-image:repeating-linear-gradient(90deg,#fff 0 2px,transparent 2px 9px,#fff 9px 10px,transparent 10px 16px,#fff 16px 19px,transparent 19px 24px)" aria-hidden="true"></div>
            <div class="absolute -right-32 -top-32 size-[28rem] rounded-full bg-brand-500/25 blur-3xl" aria-hidden="true"></div>
            <div class="relative flex h-full flex-col justify-between p-12">
                <div class="flex items-center gap-3"><x-admin.logo size="size-10" /><span class="text-2xl font-extrabold tracking-tight text-white">poson<span class="text-brand-400">.uz</span></span></div>
                <div>
                    <p class="max-w-md text-3xl font-bold leading-tight tracking-tight text-white">{{ __('admin.auth.tagline') }}</p>
                    <ul class="mt-8 space-y-3 text-slate-300">
                        <li class="flex items-center gap-3"><span class="grid size-8 place-items-center rounded-lg bg-white/10 text-brand-300"><x-admin.icon name="inbox" size="size-4" /></span>{{ __('admin.auth.points.leads') }}</li>
                        <li class="flex items-center gap-3"><span class="grid size-8 place-items-center rounded-lg bg-white/10 text-brand-300"><x-admin.icon name="card" size="size-4" /></span>{{ __('admin.auth.points.payments') }}</li>
                        <li class="flex items-center gap-3"><span class="grid size-8 place-items-center rounded-lg bg-white/10 text-brand-300"><x-admin.icon name="telegram" size="size-4" /></span>{{ __('admin.auth.points.telegram') }}</li>
                    </ul>
                </div>
                <p class="text-sm text-slate-500">© {{ date('Y') }} Poson</p>
            </div>
        </div>

        <div class="flex flex-col px-5 py-6 sm:px-10">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2 lg:invisible"><x-admin.logo size="size-9" /><span class="text-xl font-extrabold tracking-tight">poson<span class="text-brand-500">.uz</span></span></div>
                <x-admin.locale-switch variant="segmented" />
            </div>

            <main id="main" class="mx-auto flex w-full max-w-sm flex-1 flex-col justify-center py-10">
                {{ $slot }}
            </main>
        </div>
    </div>

    <x-admin.toast-stack />

    @livewireScripts
</body>
</html>
