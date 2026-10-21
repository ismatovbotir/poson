{{-- Row actions of a spec: ★ key spec, skip / restore (category rows), remove (extras). --}}
@php($isKey = $row['is_key'] && ! $skipped)
<button
    type="button"
    wire:click="toggleKey({{ $attribute->id }})"
    @disabled($skipped)
    aria-pressed="{{ $isKey ? 'true' : 'false' }}"
    @class(['grid size-9 place-items-center rounded-lg disabled:opacity-30', 'text-warn' => $isKey, 'text-slate-300 hover:text-slate-500' => ! $isKey])
    title="{{ __('admin.products.specs.key') }}"
    aria-label="{{ __('admin.products.specs.key') }}: {{ $attribute->name }}"
    data-key-toggle
><x-admin.icon name="star" :style="$isKey ? 'fill: currentColor' : 'fill: none'" /></button>

@if ($meta->isExtra())
    <button type="button" wire:click="removeExtra({{ $attribute->id }})" @class(['rounded-lg text-xs font-semibold text-slate-500 hover:bg-red-50 hover:text-danger', 'h-9 px-2' => $compact, 'h-9 w-24' => ! $compact]) aria-label="{{ __('admin.products.specs.remove') }}: {{ $attribute->name }}">{{ __('admin.products.specs.remove') }}</button>
@else
    <button
        type="button"
        wire:click="toggleSkip({{ $attribute->id }})"
        @disabled($meta->required)
        title="{{ $meta->required ? __('admin.products.specs.required_not_skippable') : '' }}"
        @class(['rounded-lg text-xs font-semibold text-slate-500 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent', 'h-9 px-2' => $compact, 'h-9 w-24' => ! $compact])
        aria-label="{{ $skipped ? __('admin.products.specs.restore') : __('admin.products.specs.skip') }}: {{ $attribute->name }}"
        data-skip-toggle
    >{{ $skipped ? __('admin.products.specs.restore') : __('admin.products.specs.skip') }}</button>
@endif
