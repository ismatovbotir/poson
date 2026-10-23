{{-- Quote status badge; a draft / sent quote past its validity also shows "expired". --}}
<span class="inline-flex flex-wrap gap-1">
    <x-admin.badge :color="$quote->status->color()" dot data-status="{{ $quote->status->value }}">{{ $quote->status->label() }}</x-admin.badge>
    @if ($quote->status->isEditable() && $quote->isExpired())
        <x-admin.badge color="amber" data-overdue>{{ __('crm.quotes.overdue') }}</x-admin.badge>
    @endif
</span>
