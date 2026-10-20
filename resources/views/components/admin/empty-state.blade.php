@props(['icon' => 'inbox', 'title' => null, 'body' => null])

{{-- Empty list / no results. Slot: action buttons. --}}
<div {{ $attributes->class(['rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-14 text-center']) }}>
    <span class="mx-auto grid size-12 place-items-center rounded-full bg-brand-50 text-brand-600">
        <x-admin.icon :name="$icon" size="size-6" />
    </span>
    <h3 class="mt-4 text-base font-semibold">{{ $title ?? __('admin.empty.title') }}</h3>
    <p class="mx-auto mt-1 max-w-sm text-sm text-slate-500">{{ $body ?? __('admin.empty.body') }}</p>
    @if (trim($slot) !== '')
        <div class="mt-5 flex justify-center gap-2">{{ $slot }}</div>
    @endif
</div>
