{{-- What the lead asks for: its products ("Name ×2, +3"), else the message excerpt. --}}
@php
    /** @var \App\Models\Lead $lead */
    $first = $lead->items->first();
@endphp
@if ($first)
    <span>{{ $first->product_name }}@if ($first->qty > 1) <span class="tabular-nums">×{{ $first->qty }}</span>@endif</span>@if ($lead->items->count() > 1)<span class="text-slate-400">, {{ __('crm.leads.more_items', ['count' => $lead->items->count() - 1]) }}</span>@endif
@elseif (filled($lead->message))
    {{ \Illuminate\Support\Str::limit($lead->message, 90) }}
@else
    <span class="text-slate-400">{{ __('admin.common.empty_value') }}</span>
@endif
