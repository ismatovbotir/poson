@if ($vacancy->is_published && ! $vacancy->isPublic())
    <x-admin.badge color="amber" :dot="true">{{ __('careers.admin.state.expired') }}</x-admin.badge>
@elseif ($vacancy->is_published)
    <x-admin.badge color="brand" :dot="true">{{ __('careers.admin.state.published') }}</x-admin.badge>
@else
    <x-admin.badge color="muted" :dot="true">{{ __('careers.admin.state.draft') }}</x-admin.badge>
@endif
