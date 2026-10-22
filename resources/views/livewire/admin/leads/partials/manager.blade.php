{{-- Manager avatar with name, or the dashed "unassigned" circle (admin kit leads board). --}}
@if ($lead->manager)
    <span class="flex min-w-0 items-center gap-2" title="{{ $lead->manager->name }}">
        <span class="grid size-7 shrink-0 place-items-center rounded-full bg-brand-600 text-[11px] font-bold text-white" aria-hidden="true">{{ $lead->manager->initials() }}</span>
        <span class="truncate text-slate-600">{{ $lead->manager->name }}</span>
    </span>
@else
    <span class="flex items-center gap-2 text-slate-400" data-unassigned>
        <span class="grid size-7 shrink-0 place-items-center rounded-full border border-dashed border-slate-300" aria-hidden="true"><x-admin.icon name="plus" size="size-3.5" /></span>
        <span class="text-xs">{{ __('crm.leads.unassigned') }}</span>
    </span>
@endif
