{{--
    Roll types of a label product (plan 08), in site picker order (the first one is
    preselected). Attached = available; hidden (inactive) roll types are not offered.
    Editable while the price API owns the prices.
--}}
@php
    $options = $this->rollTypeOptions->keyBy('id');
    $selected = collect($form->roll_type_ids)->map(fn ($id) => $options->get((int) $id))->filter()->values();
    $available = $options->reject(fn ($rollType) => in_array($rollType->id, array_map('intval', $form->roll_type_ids), true))->values();
@endphp

<div data-roll-types>
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <p class="text-sm font-semibold">{{ __('admin.products.roll_types.title') }}</p>
        @if ($canViewRollTypes)
            <a href="{{ route('admin.roll-types.index') }}" wire:navigate class="text-xs font-semibold text-brand-600 hover:text-brand-700">{{ __('admin.products.roll_types.manage') }}</a>
        @endif
    </div>
    <p class="mt-0.5 text-xs text-slate-500">{{ __('admin.products.roll_types.hint') }}</p>

    @if ($selected->isNotEmpty())
        <ol class="mt-3 divide-y divide-slate-100 rounded-xl border border-slate-200" data-roll-types-selected>
            @foreach ($selected as $rollType)
                <li wire:key="roll-selected-{{ $rollType->id }}" class="flex items-center gap-2 px-3 py-2 text-sm" data-roll-type="{{ $rollType->id }}">
                    <span class="w-5 text-xs tabular-nums text-slate-400">{{ $loop->iteration }}.</span>
                    <span class="min-w-0 flex-1 truncate">{{ $rollType->label() }}</span>
                    @if ($loop->first)<x-admin.badge color="sky">{{ __('admin.products.roll_types.default') }}</x-admin.badge>@endif
                    @unless ($rollType->is_active)<x-admin.badge color="muted">{{ __('admin.roll_types.inactive') }}</x-admin.badge>@endunless
                    @if ($canSave)
                        <button type="button" wire:click="moveRollType({{ $rollType->id }}, -1)" @disabled($loop->first) class="grid size-8 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30" aria-label="{{ __('admin.products.roll_types.move_up') }}: {{ $rollType->label() }}"><x-admin.icon name="chevron-down" size="size-4" class="rotate-180" /></button>
                        <button type="button" wire:click="moveRollType({{ $rollType->id }}, 1)" @disabled($loop->last) class="grid size-8 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30" aria-label="{{ __('admin.products.roll_types.move_down') }}: {{ $rollType->label() }}"><x-admin.icon name="chevron-down" size="size-4" /></button>
                        <button type="button" wire:click="toggleRollType({{ $rollType->id }})" class="grid size-8 place-items-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-danger" aria-label="{{ __('admin.products.roll_types.remove') }}: {{ $rollType->label() }}"><x-admin.icon name="x" size="size-4" /></button>
                    @endif
                </li>
            @endforeach
        </ol>
    @else
        <p class="mt-3 rounded-xl border border-dashed border-slate-300 px-3 py-3 text-sm text-slate-500" data-roll-types-empty>{{ __('admin.products.roll_types.empty') }}</p>
    @endif

    @if ($canSave && $available->isNotEmpty())
        <div class="mt-3 flex flex-wrap gap-2" data-roll-types-available>
            @foreach ($available as $rollType)
                <button type="button" wire:key="roll-available-{{ $rollType->id }}" wire:click="toggleRollType({{ $rollType->id }})" @class([
                    'inline-flex h-8 items-center gap-1.5 rounded-lg border px-2.5 text-xs font-semibold',
                    'border-slate-200 text-slate-700 hover:border-brand-500 hover:text-brand-700' => $rollType->is_active,
                    'border-dashed border-slate-200 text-slate-400 hover:text-slate-600' => ! $rollType->is_active,
                ])><x-admin.icon name="plus" size="size-3.5" />{{ $rollType->label() }}</button>
            @endforeach
        </div>
    @elseif ($options->isEmpty())
        <p class="mt-2 text-xs text-slate-500">{{ __('admin.products.roll_types.none_exist') }}</p>
    @endif

    <x-admin.field-error name="form.roll_type_ids" />
</div>
