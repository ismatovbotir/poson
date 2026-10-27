@if ($rollType->is_active)
    <x-admin.badge color="brand" pill dot>{{ __('admin.roll_types.active') }}</x-admin.badge>
@else
    <x-admin.badge color="muted" pill dot>{{ __('admin.roll_types.inactive') }}</x-admin.badge>
@endif
