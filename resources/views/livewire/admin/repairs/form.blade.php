@php
    $backHref = $repair ? route('admin.repairs.show', $repair) : route('admin.repairs.index');
    $customer = $this->customer;
@endphp

<form wire:submit="save" novalidate>
    <x-admin.page-header :title="$title" :back-href="$backHref" :back-label="$repair ? $repair->number : __('repairs.title')">
        <x-slot:actions>
            <div class="hidden gap-2 lg:flex">
                <x-admin.button variant="secondary" :href="$backHref" wire:navigate>{{ __('admin.common.cancel') }}</x-admin.button>
                <x-admin.button type="submit" icon="check" loading="save">{{ __('admin.common.save') }}</x-admin.button>
            </div>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-3 lg:gap-6">
        <div class="min-w-0 space-y-4 lg:col-span-2 lg:space-y-6">
            {{-- Customer and contact --}}
            <x-admin.card :title="__('repairs.sections.contact')">
                <div class="space-y-5">
                    <div>
                        <p class="text-sm font-semibold">{{ __('repairs.fields.customer_id') }}</p>
                        @if ($customer)
                            <p class="mt-1.5 flex items-center justify-between gap-2 rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-800" data-customer-chosen>
                                <span class="flex min-w-0 items-center gap-1.5"><x-admin.icon name="building" size="size-4" /><span class="truncate font-semibold">{{ $customer->name }}</span></span>
                                <button type="button" wire:click="clearCustomer" class="shrink-0 text-xs font-semibold underline">{{ __('repairs.customer.clear') }}</button>
                            </p>
                        @else
                            <div class="relative mt-1.5" x-data="{ open: false }" x-on:click.outside="open = false">
                                <label class="relative block">
                                    <span class="sr-only">{{ __('repairs.customer.search') }}</span>
                                    <x-admin.icon name="search" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                                    <input wire:model.live.debounce.300ms="customerSearch" x-on:focus="open = true" x-on:input="open = true" type="search" maxlength="64" autocomplete="off" placeholder="{{ __('repairs.customer.search') }}" class="h-11 w-full rounded-lg border border-slate-200 bg-white pl-10 pr-3 text-base outline-none placeholder:text-slate-400 focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 sm:text-sm lg:h-10">
                                </label>
                                @if (mb_strlen(trim($customerSearch)) >= 2)
                                    <div x-show="open" class="absolute inset-x-0 top-full z-20 mt-1 max-h-72 overflow-y-auto rounded-xl border border-slate-200 bg-white p-1 shadow-xl" data-customer-results>
                                        @forelse ($this->customerResults as $match)
                                            <button type="button" wire:key="pick-{{ $match->id }}" wire:click="pickCustomer({{ $match->id }})" class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm hover:bg-slate-50">
                                                <span class="min-w-0 flex-1"><span class="block truncate font-medium">{{ $match->name }}</span><span class="block text-xs text-slate-500">{{ \App\Support\Phone::display($match->phone) }}</span></span>
                                            </button>
                                        @empty
                                            <p class="px-3 py-3 text-sm text-slate-500">{{ __('repairs.customer.no_results') }}</p>
                                        @endforelse
                                    </div>
                                @endif
                            </div>
                            <p class="mt-1 text-xs text-slate-500">{{ __('repairs.customer.hint') }}</p>
                        @endif
                        <x-admin.field-error name="form.customer_id" />
                    </div>

                    @if ($this->contactOptions !== [])
                        <x-admin.select wire:model.live="form.contact_id" :label="__('repairs.fields.contact_id')" :placeholder="__('repairs.customer.no_contact')" :options="$this->contactOptions" />
                    @endif

                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-admin.input wire:model="form.contact_name" :label="__('repairs.fields.contact_name')" required maxlength="120" autocomplete="off" />
                        <x-admin.input wire:model="form.contact_phone" type="tel" inputmode="tel" :label="__('repairs.fields.contact_phone')" required maxlength="32" placeholder="+998 90 123 45 67" autocomplete="off" />
                        <x-admin.input wire:model="form.company" :label="__('repairs.fields.company')" maxlength="160" autocomplete="off" class="sm:col-span-2" />
                    </div>
                </div>
            </x-admin.card>

            {{-- Device --}}
            <x-admin.card :title="__('repairs.sections.device')">
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-admin.select wire:model="form.device_type" :label="__('repairs.fields.device_type')" required :options="$deviceTypes" />
                    <x-admin.input wire:model="form.serial_number" :label="__('repairs.fields.serial_number')" maxlength="64" autocomplete="off" class="font-mono" />
                    <x-admin.input wire:model="form.device_brand" :label="__('repairs.fields.device_brand')" maxlength="80" autocomplete="off" />
                    <x-admin.input wire:model="form.device_model" :label="__('repairs.fields.device_model')" maxlength="120" autocomplete="off" />
                    <x-admin.input wire:model="form.accessories" :label="__('repairs.fields.accessories')" maxlength="500" :hint="__('repairs.hints.accessories')" autocomplete="off" class="sm:col-span-2" />
                </div>
            </x-admin.card>

            {{-- Intake --}}
            <x-admin.card :title="__('repairs.sections.intake')">
                <div class="space-y-5">
                    <x-admin.textarea wire:model="form.problem" :label="__('repairs.fields.problem')" required rows="3" maxlength="5000" :hint="__('repairs.hints.no_passwords')" />
                    <x-admin.textarea wire:model="form.intake_condition" :label="__('repairs.fields.intake_condition')" required rows="3" maxlength="5000" :hint="__('repairs.hints.intake_condition')" />
                </div>
            </x-admin.card>

            {{-- Diagnosis and work (edit only: filled by the engineer) --}}
            @if ($repair)
                <x-admin.card :title="__('repairs.sections.work')">
                    <div class="space-y-5">
                        <x-admin.textarea wire:model="form.diagnosis" :label="__('repairs.fields.diagnosis')" rows="3" maxlength="5000" :hint="__('repairs.hints.no_passwords')" />
                        <x-admin.textarea wire:model="form.work_done" :label="__('repairs.fields.work_done')" rows="3" maxlength="5000" />
                        <x-admin.textarea wire:model="form.parts_used" :label="__('repairs.fields.parts_used')" rows="2" maxlength="5000" :hint="__('repairs.hints.parts_used')" />
                    </div>
                </x-admin.card>
            @endif
        </div>

        <aside class="min-w-0 space-y-4 lg:sticky lg:top-24 lg:self-start lg:space-y-6">
            <x-admin.card :title="__('repairs.sections.coverage')">
                <div class="space-y-4">
                    <x-admin.select wire:model.live="form.coverage" :label="__('repairs.fields.coverage')" required :options="$coverages" />
                    @if ($form->coverage === \App\Enums\RepairCoverage::Warranty->value)
                        <x-admin.input wire:model="form.warranty_reference" :label="__('repairs.fields.warranty_reference')" required maxlength="120" :hint="__('repairs.hints.warranty_reference')" autocomplete="off" />
                    @endif
                    <x-admin.select wire:model="form.locale" :label="__('repairs.fields.locale')" required :options="$locales" />
                    @if (! $repair && $this->assigneeOptions !== [])
                        <x-admin.select wire:model="form.assigned_to_id" :label="__('repairs.fields.assigned_to')" :placeholder="__('repairs.unassigned')" :options="$this->assigneeOptions" />
                    @endif
                </div>
            </x-admin.card>

            @if ($this->canCosts)
                <x-admin.card :title="__('repairs.sections.costs')" data-costs>
                    <div class="space-y-4">
                        <x-admin.money-input wire:model="form.estimate_amount" :label="__('repairs.fields.estimate_amount')" :hint="__('repairs.hints.estimate')" />
                        <x-admin.money-input wire:model="form.final_amount" :label="__('repairs.fields.final_amount')" :hint="__('repairs.hints.final')" />
                    </div>
                </x-admin.card>
            @endif
        </aside>
    </div>

    <x-admin.sticky-save-bar :cancel-href="$backHref" />
</form>
