@props(['phone' => null])

{{-- "Non-UZ number" marker (plan 14 v2 TG-45): shown next to a valid phone that is not +998. --}}
@if (\App\Support\Phone::isForeign($phone))
    <x-admin.badge color="amber" {{ $attributes }}>{{ __('admin.common.foreign_phone') }}</x-admin.badge>
@endif
