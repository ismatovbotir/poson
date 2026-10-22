{{-- Status, type and the duplicate flag of a lead. --}}
<x-admin.badge :color="$lead->status->color()" dot data-status="{{ $lead->status->value }}">{{ $lead->status->label() }}</x-admin.badge>
@if ($withType ?? true)
    <x-admin.badge :color="$lead->type->color()">{{ $lead->type->label() }}</x-admin.badge>
@endif
@if ($lead->duplicate_of_id)
    <x-admin.badge color="amber" data-duplicate title="{{ __('crm.leads.duplicate_hint', ['days' => \App\Models\Lead::DUPLICATE_WINDOW_DAYS]) }}">{{ __('crm.leads.duplicate') }}</x-admin.badge>
@endif
