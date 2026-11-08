{{-- Status and coverage of a repair. --}}
<x-admin.badge :color="$repair->status->color()" dot data-status="{{ $repair->status->value }}">{{ $repair->status->label() }}</x-admin.badge>
@if ($repair->coverage === \App\Enums\RepairCoverage::Warranty)
    <x-admin.badge :color="$repair->coverage->color()" data-warranty>{{ $repair->coverage->label() }}</x-admin.badge>
@endif
