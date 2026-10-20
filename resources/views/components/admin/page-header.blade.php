@props(['title', 'subtitle' => null, 'backHref' => null, 'backLabel' => null])

{{--
    Page title row. Slots: "breadcrumbs" (optional), "actions" (buttons on the right).
    backHref/backLabel render a "‹ Back to list" link above the title.
--}}
<div {{ $attributes->class(['flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between']) }}>
    <div class="min-w-0">
        @if ($backHref)
            <nav class="flex items-center gap-1.5 text-sm text-slate-500" aria-label="{{ __('admin.a11y.breadcrumb') }}">
                <a href="{{ $backHref }}" wire:navigate class="inline-flex items-center gap-1 hover:text-slate-700"><x-admin.icon name="chevron-left" size="size-4" />{{ $backLabel }}</a>
            </nav>
        @elseif (isset($breadcrumbs))
            <nav class="text-sm text-slate-500" aria-label="{{ __('admin.a11y.breadcrumb') }}">{{ $breadcrumbs }}</nav>
        @endif

        <h1 class="mt-1 truncate text-2xl font-bold tracking-tight sm:text-3xl">{{ $title }}</h1>

        @if ($subtitle)
            <p class="mt-1 text-sm text-slate-500">{{ $subtitle }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
