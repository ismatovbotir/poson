{{-- A "quote" history entry (quote, customer and lead timelines). --}}
@php
    $meta = $activity->meta ?? [];
    $action = (string) ($meta['action'] ?? 'created');
    $number = (string) ($meta['number'] ?? '');
@endphp
@if ($action === 'opened')
    {{ __('crm.quotes.timeline.opened') }}
@elseif ($action === 'updated')
    {{ __('crm.quotes.timeline.updated', ['number' => $number, 'from' => \App\Support\Money::format((int) ($meta['old_total'] ?? 0)), 'to' => \App\Support\Money::format((int) ($meta['new_total'] ?? 0))]) }}
@elseif ($action === 'created' && ! empty($meta['from_number']))
    {{ __('crm.quotes.timeline.duplicated', ['number' => $number, 'from' => $meta['from_number']]) }}
@elseif (in_array($action, ['created', 'sent', 'accepted', 'rejected', 'expired', 'deleted'], true))
    {{ __('crm.quotes.timeline.'.$action, ['number' => $number]) }}
    @if ($action === 'sent' && ! empty($meta['via']))<span class="text-slate-500">· {{ __('crm.quotes.timeline.via.'.$meta['via']) }}</span>@endif
@else
    {{ $activity->type->label() }} {{ $number }}
@endif
