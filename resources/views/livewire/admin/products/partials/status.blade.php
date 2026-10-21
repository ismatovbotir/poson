{{-- Publish status (+ specs incomplete). --}}
<span class="inline-flex flex-wrap items-center gap-1.5">
    @if ($product->trashed())
        <x-admin.badge color="outline" pill dot>{{ __('admin.products.status.trashed') }}</x-admin.badge>
    @elseif ($product->is_active)
        <x-admin.badge color="brand" pill dot>{{ __('admin.products.status.active') }}</x-admin.badge>
    @else
        <x-admin.badge color="slate" pill dot>{{ __('admin.products.status.draft') }}</x-admin.badge>
    @endif
    @if ($product->has_missing_specs)
        <x-admin.badge color="amber" data-incomplete>{{ __('admin.products.status.incomplete') }}</x-admin.badge>
    @endif
</span>
