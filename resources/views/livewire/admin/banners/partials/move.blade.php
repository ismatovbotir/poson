{{-- Move the banner up / down within its list (home slider, or one category). --}}
<span class="flex shrink-0 items-center justify-center gap-0.5">
    <button type="button" wire:click="move({{ $banner->id }}, -1)" class="grid size-8 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="{{ __('admin.banners.move_up') }}: {{ $banner->title }}" data-move-up="{{ $banner->id }}"><x-admin.icon name="chevron-down" size="size-4" class="rotate-180" /></button>
    <button type="button" wire:click="move({{ $banner->id }}, 1)" class="grid size-8 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="{{ __('admin.banners.move_down') }}: {{ $banner->title }}" data-move-down="{{ $banner->id }}"><x-admin.icon name="chevron-down" size="size-4" /></button>
</span>
