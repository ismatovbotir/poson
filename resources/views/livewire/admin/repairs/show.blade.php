@php
    $tz = \App\Models\Lead::TIMEZONE;
    $when = fn ($date, $format = 'D MMM YYYY, HH:mm') => $date ? \App\Support\DateFormat::iso($date->copy()->setTimezone($tz), $format) : __('admin.common.empty_value');
    $text = fn (?string $value) => filled($value) ? $value : null;
@endphp

<div x-data="{ cancel: false }" x-on:repair-cancelled.window="cancel = false">
    <x-admin.page-header :title="$repair->company ?: $repair->contact_name" :back-href="route('admin.repairs.index')" :back-label="__('repairs.title')">
        <x-slot:actions>
            @if ($canEdit && $repair->isOpen())
                <x-admin.button variant="secondary" :href="route('admin.repairs.edit', $repair)" wire:navigate icon="edit">{{ __('admin.common.edit') }}</x-admin.button>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mt-3 flex flex-wrap items-center gap-1.5 text-sm text-slate-500">
        <span class="font-mono font-semibold text-slate-700" data-reference>{{ $repair->number }}</span>
        <span class="text-slate-300">·</span>
        @include('livewire.admin.repairs.partials.badges', ['repair' => $repair])
        <x-admin.badge color="outline">{{ $repair->device_type->label() }}</x-admin.badge>
        @if ($repair->customer)
            @if ($canViewCustomer)
                <a href="{{ route('admin.customers.show', $repair->customer) }}" wire:navigate data-customer><x-admin.badge color="brand" class="hover:bg-brand-100"><x-admin.icon name="building" size="size-3.5" />{{ $repair->customer->name }}</x-admin.badge></a>
            @else
                <x-admin.badge color="brand" data-customer><x-admin.icon name="building" size="size-3.5" />{{ $repair->customer->name }}</x-admin.badge>
            @endif
        @endif
        @if (! $canEdit && $repair->isOpen())
            <x-admin.badge color="muted" data-read-only><x-admin.icon name="eye" size="size-3.5" />{{ __('repairs.read_only') }}</x-admin.badge>
        @endif
    </div>

    <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-3 lg:gap-6">
        <div class="min-w-0 space-y-4 lg:col-span-2 lg:space-y-6">
            {{-- Status --}}
            <x-admin.card :title="__('repairs.sections.status')">
                <div class="flex flex-wrap items-center gap-2" data-status-control>
                    <x-admin.badge :color="$repair->status->color()" dot class="text-sm">{{ $repair->status->label() }}</x-admin.badge>
                    @if ($canEdit && ($statuses !== [] || $canCancel))
                        <span class="text-slate-300" aria-hidden="true">→</span>
                        @foreach ($statuses as $status)
                            <x-admin.button size="sm" variant="secondary" wire:click="changeStatus('{{ $status->value }}')" data-status-to="{{ $status->value }}">{{ $status->label() }}</x-admin.button>
                        @endforeach
                        @if ($canCancel)
                            <x-admin.button size="sm" variant="secondary" x-on:click="cancel = true" data-status-to="cancelled">{{ __('repairs.actions.cancel') }}</x-admin.button>
                        @endif
                    @endif
                </div>
                <p class="mt-3 text-xs text-slate-500">{{ __('repairs.status_changed_at') }}: {{ $when($repair->status_changed_at) }}</p>
            </x-admin.card>

            {{-- Problem and intake --}}
            <x-admin.card :title="__('repairs.sections.intake')">
                <dl class="space-y-4 text-sm">
                    <div><dt class="text-slate-500">{{ __('repairs.fields.problem') }}</dt><dd class="mt-0.5 whitespace-pre-line break-words">{{ $repair->problem }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('repairs.fields.intake_condition') }}</dt><dd class="mt-0.5 whitespace-pre-line break-words">{{ $repair->intake_condition }}</dd></div>
                    @if ($text($repair->accessories))
                        <div><dt class="text-slate-500">{{ __('repairs.fields.accessories') }}</dt><dd class="mt-0.5 break-words">{{ $repair->accessories }}</dd></div>
                    @endif
                </dl>
            </x-admin.card>

            {{-- Diagnosis and work --}}
            <x-admin.card :title="__('repairs.sections.work')">
                @if ($text($repair->diagnosis) || $text($repair->work_done) || $text($repair->parts_used))
                    <dl class="space-y-4 text-sm">
                        @foreach (['diagnosis' => $repair->diagnosis, 'work_done' => $repair->work_done, 'parts_used' => $repair->parts_used] as $field => $value)
                            @if ($text($value))
                                <div><dt class="text-slate-500">{{ __('repairs.fields.'.$field) }}</dt><dd class="mt-0.5 whitespace-pre-line break-words">{{ $value }}</dd></div>
                            @endif
                        @endforeach
                    </dl>
                @else
                    <p class="text-sm text-slate-400">{{ __('repairs.no_work') }}</p>
                @endif
            </x-admin.card>

            {{-- Activity --}}
            <x-admin.card :title="__('repairs.sections.activity')">
                @if ($canNote)
                    <form wire:submit="addActivity" class="mb-6" data-activity-form>
                        <x-admin.textarea wire:model="activityBody" rows="2" :placeholder="__('repairs.activity.placeholder')" maxlength="2000" :aria-label="__('repairs.activity.body')" />
                        <div class="mt-2 flex justify-end">
                            <x-admin.button type="submit" size="sm" loading="addActivity">{{ __('repairs.activity.save') }}</x-admin.button>
                        </div>
                    </form>
                @endif

                <ol class="space-y-4 border-l border-slate-200 pl-5" data-timeline>
                    @foreach ($this->activities as $activity)
                        <li wire:key="activity-{{ $activity->id }}" class="relative" data-activity="{{ $activity->type->value }}">
                            <span @class(['absolute -left-[1.6rem] top-1 size-2.5 rounded-full ring-4 ring-white', $activity->type->dot()]) aria-hidden="true"></span>
                            @include('livewire.admin.repairs.partials.activity', ['activity' => $activity])
                            <p class="mt-0.5 text-xs text-slate-400">{{ $activity->type->label() }} · {{ $when($activity->created_at) }}</p>
                        </li>
                    @endforeach
                </ol>
            </x-admin.card>
        </div>

        <aside class="min-w-0 space-y-4 lg:sticky lg:top-24 lg:self-start lg:space-y-6">
            {{-- Contact --}}
            <x-admin.card :title="__('repairs.sections.contact')">
                <p class="text-sm font-semibold">{{ $repair->contact_name }}</p>
                @if ($repair->company)<p class="text-sm text-slate-500">{{ $repair->company }}</p>@endif
                <div class="mt-3 flex flex-wrap gap-2">
                    <a href="tel:{{ $repair->contact_phone }}" class="inline-flex h-9 items-center gap-2 rounded-lg bg-brand-50 px-3 text-sm font-semibold text-brand-700 hover:bg-brand-100"><x-admin.icon name="phone" size="size-4" />{{ \App\Support\Phone::display($repair->contact_phone) }}</a>
                    <x-admin.foreign-phone :phone="$repair->contact_phone" />
                </div>
                @if ($repair->lead && $canViewLead)
                    <p class="mt-3 text-sm"><a href="{{ route('admin.leads.show', $repair->lead) }}" wire:navigate class="font-mono font-semibold text-brand-700 hover:underline">{{ $repair->lead->reference() }}</a></p>
                @endif
            </x-admin.card>

            {{-- Device --}}
            <x-admin.card :title="__('repairs.sections.device')">
                <dl class="space-y-2 text-sm" data-device>
                    @foreach (array_filter([
                        __('repairs.fields.device_type') => $repair->device_type->label(),
                        __('repairs.fields.device_brand') => $repair->device_brand,
                        __('repairs.fields.device_model') => $repair->device_model,
                        __('repairs.fields.serial_number') => $repair->serial_number,
                    ], fn ($value) => filled($value)) as $label => $value)
                        <div class="flex gap-3"><dt class="w-28 shrink-0 text-slate-500">{{ $label }}</dt><dd class="min-w-0 break-words">{{ $value }}</dd></div>
                    @endforeach
                </dl>
            </x-admin.card>

            {{-- Coverage --}}
            <x-admin.card :title="__('repairs.sections.coverage')">
                <p class="text-sm font-semibold">{{ $repair->coverage->label() }}</p>
                @if ($repair->coverage === \App\Enums\RepairCoverage::Warranty)
                    <p class="mt-1 break-words text-sm text-slate-600" data-warranty-reference><span class="text-slate-500">{{ __('repairs.fields.warranty_reference') }}:</span> {{ $repair->warranty_reference }}</p>
                @endif
            </x-admin.card>

            {{-- Costs: only with repairs.costs --}}
            @if ($canCosts)
                <x-admin.card :title="__('repairs.sections.costs')" data-costs>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ __('repairs.fields.estimate_amount') }}</dt><dd class="font-semibold tabular-nums" data-estimate>{{ $repair->estimate_amount !== null ? \App\Support\Money::format($repair->estimate_amount) : __('admin.common.empty_value') }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ __('repairs.fields.final_amount') }}</dt><dd class="font-semibold tabular-nums" data-final>{{ $repair->final_amount !== null ? \App\Support\Money::format($repair->final_amount) : __('admin.common.empty_value') }}</dd></div>
                        @if ($repair->coverage === \App\Enums\RepairCoverage::Paid && $repair->estimate_amount !== null)
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ __('repairs.fields.estimate_approved_at') }}</dt><dd data-approved>{{ $repair->estimate_approved_at ? $when($repair->estimate_approved_at) : __('repairs.not_approved') }}</dd></div>
                        @endif
                    </dl>
                    @if ($canApprove && $repair->coverage === \App\Enums\RepairCoverage::Paid && $repair->estimate_amount !== null && $repair->estimate_approved_at === null && $repair->isOpen() && $repair->status !== \App\Enums\RepairStatus::Ready)
                        <form wire:submit="approveEstimate" class="mt-3 space-y-2" data-approve-form>
                            <x-admin.textarea wire:model="approveNote" rows="2" maxlength="500" :label="__('repairs.fields.approval_note')" :hint="__('repairs.hints.approval_note')" required />
                            <x-admin.button type="submit" size="sm" icon="check" loading="approveEstimate" data-action="approve-estimate">{{ __('repairs.actions.approve_estimate') }}</x-admin.button>
                        </form>
                    @endif
                </x-admin.card>
            @endif

            {{-- Engineer --}}
            <x-admin.card :title="__('repairs.fields.assigned_to')">
                @include('livewire.admin.repairs.partials.engineer', ['repair' => $repair])
                @if ($canTake || $canRelease)
                    <div class="mt-3 flex gap-2">
                        @if ($canTake)
                            <x-admin.button size="sm" wire:click="take" loading="take" data-action="take">{{ __('repairs.actions.take') }}</x-admin.button>
                        @endif
                        @if ($canRelease)
                            <x-admin.button size="sm" variant="secondary" wire:click="release" loading="release" data-action="release">{{ __('repairs.actions.release') }}</x-admin.button>
                        @endif
                    </div>
                @endif
                @if ($canAssign && $this->candidates !== [])
                    <div class="mt-4 flex items-end gap-2" data-assign>
                        <x-admin.select wire:model="assignTo" class="min-w-0 flex-1" :label="__('repairs.actions.assign_to')" :placeholder="__('repairs.unassigned')" :options="$this->candidates" />
                        <x-admin.button variant="secondary" wire:click="assign" loading="assign">{{ __('repairs.actions.assign') }}</x-admin.button>
                    </div>
                @endif
            </x-admin.card>

            <x-admin.card :title="__('repairs.sections.details')">
                <dl class="space-y-2 text-sm">
                    @foreach (array_filter([
                        __('repairs.fields.locale') => __('repairs.locales.'.$repair->locale),
                        __('repairs.fields.received_at') => $when($repair->received_at),
                        __('repairs.fields.created_by') => $repair->createdBy?->name,
                        __('repairs.fields.closed_at') => $repair->closed_at ? $when($repair->closed_at) : null,
                    ], fn ($value) => filled($value)) as $label => $value)
                        <div class="flex gap-3"><dt class="w-28 shrink-0 text-slate-500">{{ $label }}</dt><dd class="min-w-0 break-words">{{ $value }}</dd></div>
                    @endforeach
                </dl>
            </x-admin.card>
        </aside>
    </div>

    {{-- Cancel: a note is required --}}
    @if ($canCancel)
        <div x-cloak x-show="cancel" class="fixed inset-0 z-[60] flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" aria-labelledby="repair-cancel-title" x-on:keydown.escape.window="cancel = false">
            <div x-show="cancel" x-transition.opacity class="absolute inset-0 bg-slate-900/50" x-on:click="cancel = false"></div>
            <form wire:submit="cancelRepair" x-show="cancel" x-transition x-trap.noscroll="cancel" class="relative w-full rounded-t-2xl bg-white p-5 pb-[calc(1.25rem+env(safe-area-inset-bottom))] shadow-2xl sm:max-w-md sm:rounded-2xl sm:pb-5" data-cancel-modal>
                <h2 id="repair-cancel-title" class="text-base font-semibold">{{ __('repairs.cancel.title') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ __('repairs.cancel.body') }}</p>
                <div class="mt-4">
                    <x-admin.textarea wire:model="cancelNote" :label="__('repairs.fields.note')" required rows="3" maxlength="2000" />
                </div>
                <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button type="button" x-on:click="cancel = false" class="h-11 rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50 sm:h-10">{{ __('admin.common.cancel') }}</button>
                    <x-admin.button type="submit" variant="danger" loading="cancelRepair">{{ __('repairs.actions.cancel') }}</x-admin.button>
                </div>
            </form>
        </div>
    @endif
</div>
