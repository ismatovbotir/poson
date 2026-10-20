@props(['name', 'id' => null])

{{-- Validation message for a field (used by the admin inputs). --}}
@error($name)
    <span @if ($id) id="{{ $id }}" @endif {{ $attributes->class(['mt-1 flex items-center gap-1 text-xs text-danger']) }}>
        <x-admin.icon name="alert" size="size-3.5" />{{ $message }}
    </span>
@enderror
