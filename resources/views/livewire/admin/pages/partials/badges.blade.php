<span class="flex flex-wrap gap-1.5">
    @if ($page->is_active)
        <x-admin.badge color="brand" pill dot>{{ __('admin.pages.active') }}</x-admin.badge>
    @else
        <x-admin.badge color="muted" pill dot>{{ __('admin.pages.inactive') }}</x-admin.badge>
    @endif
    @if ($page->needs_review)
        <x-admin.badge color="amber" pill data-needs-review>{{ __('admin.pages.needs_review') }}</x-admin.badge>
    @endif
    @if ($page->is_system)
        <x-admin.badge color="outline" pill>{{ __('admin.pages.system') }}</x-admin.badge>
    @endif
</span>
