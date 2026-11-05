{{-- One timeline entry: the system text from meta, then the free-text body. No amounts are ever shown here. --}}
@php
    /** @var \App\Models\Activity $activity */
    $meta = $activity->meta ?? [];
    $author = $activity->user?->name;
    $statusLabel = fn (?string $value) => \App\Enums\RepairStatus::tryFrom((string) $value)?->label() ?? $value;
    $coverageLabel = fn (?string $value) => \App\Enums\RepairCoverage::tryFrom((string) $value)?->label() ?? $value;
@endphp
<p class="text-sm">
    @if ($author)<b class="font-semibold">{{ $author }}</b> · @endif
    @switch($activity->type)
        @case(\App\Enums\ActivityType::Created)
            {{ __('repairs.timeline.created') }}
            @break
        @case(\App\Enums\ActivityType::StatusChange)
            {{ __('repairs.timeline.status', ['from' => $statusLabel($meta['from'] ?? null), 'to' => $statusLabel($meta['to'] ?? null)]) }}
            @break
        @case(\App\Enums\ActivityType::Assignment)
            @switch($meta['action'] ?? 'assign')
                @case('take') {{ __('repairs.timeline.took') }} @break
                @case('release') {{ __('repairs.timeline.released') }} @break
                @case('handover') {{ __('repairs.timeline.handover', ['from' => $meta['from_name'] ?? __('repairs.unassigned'), 'to' => $meta['to_name'] ?? __('repairs.unassigned')]) }} @break
                @default {{ __('repairs.timeline.assigned', ['from' => $meta['from_name'] ?? __('repairs.unassigned'), 'to' => $meta['to_name'] ?? __('repairs.unassigned')]) }}
            @endswitch
            @break
        @case(\App\Enums\ActivityType::Repair)
            @switch($meta['event'] ?? '')
                @case('estimate_approved') {{ __('repairs.timeline.estimate_approved') }} @break
                @case('estimate_changed') {{ __('repairs.timeline.estimate_changed') }}@if (! empty($meta['approval_cleared'])) <span class="text-amber-700">· {{ __('repairs.timeline.approval_cleared') }}</span>@endif @break
                @case('coverage_changed') {{ __('repairs.timeline.coverage_changed', ['to' => $coverageLabel($meta['to'] ?? null)]) }} @break
                @default {{ $activity->type->label() }}
            @endswitch
            @break
        @default
            {{ $activity->type->label() }}
    @endswitch
</p>
@if (filled($activity->body))
    <p class="mt-1 whitespace-pre-line break-words rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">{{ $activity->body }}</p>
@endif
