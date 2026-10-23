{{-- One timeline entry: the system text from meta, then the free-text body. --}}
@php
    /** @var \App\Models\Activity $activity */
    $meta = $activity->meta ?? [];
    $author = $activity->user?->name;
    $statusLabel = fn (?string $value) => \App\Enums\LeadStatus::tryFrom((string) $value)?->label() ?? $value;
@endphp
<p class="text-sm">
    @if ($author)<b class="font-semibold">{{ $author }}</b> · @endif
    @switch($activity->type)
        @case(\App\Enums\ActivityType::Created)
            {{ __('crm.leads.timeline.created', ['source' => \App\Enums\LeadSource::tryFrom($meta['source'] ?? '')?->label() ?? '']) }}
            @if (! empty($meta['auto_assigned']))<span class="text-slate-500">· {{ __('crm.leads.timeline.auto_assigned') }}</span>@endif
            @if (! empty($meta['duplicate_of_id']))<span class="text-slate-500">· {{ __('crm.leads.timeline.duplicate_of', ['reference' => \App\Models\Lead::REFERENCE_PREFIX.$meta['duplicate_of_id']]) }}</span>@endif
            @break
        @case(\App\Enums\ActivityType::StatusChange)
            {{ ! empty($meta['reopened']) ? __('crm.leads.timeline.reopened') : __('crm.leads.timeline.status', ['from' => $statusLabel($meta['from'] ?? null), 'to' => $statusLabel($meta['to'] ?? null)]) }}
            @if (! empty($meta['reason']))<span class="text-slate-500">· {{ \App\Enums\LeadLostReason::tryFrom($meta['reason'])?->label() }}</span>@endif
            @break
        @case(\App\Enums\ActivityType::Notification)
            @php($chatName = __('crm.leads.timeline.chat.'.(($meta['chat'] ?? 'sales') === 'service' ? 'service' : 'sales')))
            {{ ($meta['result'] ?? null) === 'sent'
                ? __('crm.leads.timeline.telegram_sent', ['chat' => $chatName])
                : __('crm.leads.timeline.telegram_failed', ['chat' => $chatName, 'code' => $meta['code'] ?? '—']) }}
            @break
        @case(\App\Enums\ActivityType::Quote)
            @include('livewire.admin.quotes.partials.activity', ['activity' => $activity])
            @break
        @case(\App\Enums\ActivityType::Conversion)
            {{ __(! empty($meta['created']) ? 'crm.customers.timeline.lead_converted_new' : 'crm.customers.timeline.lead_converted', ['name' => $meta['customer_name'] ?? '']) }}
            @break
        @case(\App\Enums\ActivityType::Assignment)
            @switch($meta['action'] ?? 'assign')
                @case('take') {{ __('crm.leads.timeline.took') }} @break
                @case('release') {{ __('crm.leads.timeline.released') }} @break
                @default {{ __('crm.leads.timeline.assigned', ['from' => $meta['from_name'] ?? __('crm.leads.unassigned'), 'to' => $meta['to_name'] ?? __('crm.leads.unassigned')]) }}
            @endswitch
            @break
        @default
            {{ $activity->type->label() }}
    @endswitch
</p>
@if (filled($activity->body))
    <p class="mt-1 whitespace-pre-line break-words rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">{{ $activity->body }}</p>
@endif
