@if ($brand->is_active)
    <x-admin.badge color="brand" pill dot>{{ __('admin.brands.active') }}</x-admin.badge>
@else
    <x-admin.badge color="muted" pill dot>{{ __('admin.brands.inactive') }}</x-admin.badge>
@endif
