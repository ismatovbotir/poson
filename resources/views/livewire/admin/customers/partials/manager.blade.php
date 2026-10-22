{{-- Customer manager avatar with name, or "unassigned". --}}
@if ($customer->manager)
    <span class="flex min-w-0 items-center gap-2" title="{{ $customer->manager->name }}">
        <span class="grid size-7 shrink-0 place-items-center rounded-full bg-brand-600 text-[11px] font-bold text-white" aria-hidden="true">{{ $customer->manager->initials() }}</span>
        <span class="truncate text-slate-600">{{ $customer->manager->name }}</span>
    </span>
@else
    <span class="text-xs text-slate-400" data-unassigned>{{ __('crm.customers.unassigned') }}</span>
@endif
