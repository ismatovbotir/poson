@php
    $backHref = $customer ? route('admin.customers.show', $customer) : route('admin.customers.index');
@endphp

<form wire:submit="save" novalidate>
    <x-admin.page-header :title="$title" :back-href="$backHref" :back-label="$customer ? $customer->name : __('crm.customers.title')">
        <x-slot:actions>
            <div class="hidden gap-2 lg:flex">
                <x-admin.button variant="secondary" :href="$backHref" wire:navigate>{{ __('admin.common.cancel') }}</x-admin.button>
                <x-admin.button type="submit" icon="check" loading="save">{{ __('admin.common.save') }}</x-admin.button>
            </div>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-3 lg:gap-6">
        <div class="min-w-0 space-y-4 lg:col-span-2 lg:space-y-6">
            <x-admin.card :title="__('crm.customers.sections.requisites')">
                <div class="grid gap-5 sm:grid-cols-2">
                    <fieldset class="sm:col-span-2">
                        <legend class="text-sm font-semibold">{{ __('crm.customers.fields.type') }}</legend>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach (\App\Enums\CustomerType::cases() as $customerType)
                                <label class="cursor-pointer">
                                    <input type="radio" value="{{ $customerType->value }}" wire:model.live="form.type" class="peer sr-only">
                                    <span class="inline-flex h-9 items-center rounded-lg border border-slate-200 px-3 text-sm font-medium text-slate-600 peer-checked:border-brand-600 peer-checked:bg-brand-50 peer-checked:text-brand-700 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500">{{ $customerType->label() }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                    <x-admin.input wire:model="form.name" :label="$form->type === 'individual' ? __('crm.customers.fields.full_name') : __('crm.customers.fields.name')" required maxlength="255" autocomplete="off" class="sm:col-span-2" />
                    <x-admin.input wire:model="form.inn" :label="__('crm.customers.fields.inn')" inputmode="numeric" maxlength="9" :hint="__('crm.customers.inn_hint')" autocomplete="off" />
                    <x-admin.input wire:model="form.phone" type="tel" inputmode="tel" :label="__('crm.customers.fields.phone')" maxlength="32" placeholder="+998 90 123 45 67" autocomplete="off" />
                    <x-admin.input wire:model="form.email" type="email" :label="__('crm.customers.fields.email')" maxlength="255" autocomplete="off" />
                    <x-admin.input wire:model="form.address" :label="__('crm.customers.fields.address')" maxlength="500" autocomplete="off" />
                    <x-admin.textarea wire:model="form.notes" :label="__('crm.customers.fields.notes')" rows="3" maxlength="5000" class="sm:col-span-2" />
                </div>
            </x-admin.card>

            <x-admin.card :title="__('crm.customers.sections.contacts')">
                <x-slot:actions>
                    <x-admin.button size="sm" variant="soft" icon="plus" wire:click="addContact">{{ __('crm.customers.contacts.add') }}</x-admin.button>
                </x-slot:actions>
                <x-admin.field-error name="form.contacts" />

                @if ($form->contacts === [])
                    <p class="text-sm text-slate-500">{{ __('crm.customers.contacts.empty') }}</p>
                @else
                    <div class="space-y-4" data-contacts>
                        @foreach ($form->contacts as $index => $contact)
                            <fieldset wire:key="contact-{{ $contact['id'] ?? 'new-'.$index }}" class="rounded-xl border border-slate-200 p-4">
                                <legend class="sr-only">{{ __('crm.customers.contacts.title', ['number' => $index + 1]) }}</legend>
                                <div class="flex items-center justify-between gap-2">
                                    <label class="flex items-center gap-2 text-sm font-medium">
                                        <input type="radio" wire:model="form.primary" value="{{ $index }}" class="size-4 accent-brand-600">{{ __('crm.customers.contacts.primary') }}
                                    </label>
                                    <button type="button" wire:click="removeContact({{ $index }})" class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-danger" aria-label="{{ __('crm.customers.contacts.remove') }}"><x-admin.icon name="trash" size="size-4" /></button>
                                </div>
                                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                    <x-admin.input wire:model="form.contacts.{{ $index }}.name" :label="__('crm.customers.contacts.name')" required maxlength="255" autocomplete="off" />
                                    <x-admin.input wire:model="form.contacts.{{ $index }}.position" :label="__('crm.customers.contacts.position')" maxlength="255" autocomplete="off" />
                                    <x-admin.input wire:model="form.contacts.{{ $index }}.phone" type="tel" inputmode="tel" :label="__('crm.customers.contacts.phone')" maxlength="32" autocomplete="off" />
                                    <x-admin.input wire:model="form.contacts.{{ $index }}.email" type="email" :label="__('crm.customers.contacts.email')" maxlength="255" autocomplete="off" />
                                    <x-admin.input wire:model="form.contacts.{{ $index }}.telegram" :label="__('crm.customers.contacts.telegram')" maxlength="64" placeholder="@username" autocomplete="off" />
                                </div>
                            </fieldset>
                        @endforeach
                    </div>
                @endif
            </x-admin.card>
        </div>

        <aside class="min-w-0 space-y-4 lg:sticky lg:top-24 lg:self-start lg:space-y-6">
            <x-admin.card :title="__('crm.customers.sections.sales')">
                <div class="space-y-4">
                    <x-admin.select wire:model="form.manager_id" :label="__('crm.customers.fields.manager')" :placeholder="__('crm.customers.unassigned')" :options="$this->managerOptions" />
                    @if ($canChangePriceLevel)
                        <x-admin.select wire:model="form.price_level" :label="__('crm.customers.fields.price_level')" required :hint="__('crm.customers.price_level_hint')" :options="collect(\App\Enums\PriceLevel::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" />
                    @else
                        <div data-price-level-readonly>
                            <p class="text-sm font-semibold">{{ __('crm.customers.fields.price_level') }}</p>
                            <p class="mt-1.5"><x-admin.badge :color="$storedPriceLevel->color()">{{ $storedPriceLevel->label() }}</x-admin.badge></p>
                            <p class="mt-1 text-xs text-slate-500">{{ __('crm.customers.price_level_admin_only') }}</p>
                        </div>
                    @endif
                    @if ($customer)
                        <div>
                            <p class="text-sm font-semibold">{{ __('crm.customers.fields.telegram_id') }}</p>
                            <p class="mt-1.5 font-mono text-sm text-slate-600" data-telegram-id>{{ $customer->telegram_id ?? __('admin.common.empty_value') }}</p>
                            <p class="mt-1 text-xs text-slate-500">{{ __('crm.customers.telegram_id_hint') }}</p>
                        </div>
                    @endif
                </div>
            </x-admin.card>
        </aside>
    </div>

    <x-admin.sticky-save-bar :cancel-href="$backHref" />
</form>
