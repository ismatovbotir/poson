@props(['cancelHref' => null, 'action' => 'save', 'label' => null])

{{--
    Save bar fixed at the bottom of a form: always on phones (above the tab bar),
    slides in on desktop when the Livewire form is dirty (wire:dirty).
    Place inside the <form wire:submit="…">; the button submits it.
--}}
<div
    wire:dirty.class="lg:translate-y-0"
    wire:dirty.class.remove="lg:translate-y-full"
    {{ $attributes->class(['fixed inset-x-0 bottom-[calc(3.75rem+env(safe-area-inset-bottom))] z-20 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur-md transition-transform lg:bottom-0 lg:left-[18rem] lg:translate-y-full lg:px-8']) }}
>
    <div class="mx-auto flex max-w-[1600px] items-center gap-3">
        <p class="hidden min-w-0 flex-1 items-center gap-2 text-sm text-slate-600 sm:flex" aria-live="polite">
            <span wire:dirty class="flex items-center gap-2"><span class="size-2 rounded-full bg-warn" aria-hidden="true"></span>{{ __('admin.form.unsaved') }}</span>
            <span wire:dirty.remove class="flex items-center gap-2"><span class="size-2 rounded-full bg-ok" aria-hidden="true"></span>{{ __('admin.form.all_saved') }}</span>
        </p>
        @if ($cancelHref)
            <a href="{{ $cancelHref }}" wire:navigate class="flex h-11 flex-1 items-center justify-center rounded-lg border border-slate-200 text-sm font-semibold text-slate-700 hover:bg-slate-50 sm:flex-none sm:px-5 lg:h-10">{{ __('admin.common.cancel') }}</a>
        @endif
        <x-admin.button type="submit" :loading="$action" class="flex-[2] sm:flex-none sm:px-6">{{ $label ?? __('admin.common.save') }}</x-admin.button>
    </div>
</div>
