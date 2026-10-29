@php($t = 'checkout.settings')

{{-- Settings → Checkout (plan 11b). Admin only (settings.checkout); changes are audited, the UzQR code alerts finance + sales. --}}
<form wire:submit="save" novalidate class="pb-28" data-checkout-settings>
    <x-admin.page-header :title="__($t.'.title')" :subtitle="__($t.'.subtitle')">
        <x-slot:breadcrumbs>{{ __('menu.groups.settings') }} <span class="text-slate-300">/</span> {{ __($t.'.title') }}</x-slot:breadcrumbs>
    </x-admin.page-header>

    <div class="mt-5 max-w-4xl space-y-4 lg:space-y-6">
        <x-admin.card :title="__($t.'.regions_title')" :description="__($t.'.regions_hint')">
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach (\App\Enums\Region::cases() as $region)
                    <x-admin.input wire:key="fee-{{ $region->value }}" wire:model="form.region_fees.{{ $region->value }}" inputmode="numeric" :label="$region->label()" :placeholder="__($t.'.by_manager')" maxlength="20" data-region-fee="{{ $region->value }}" />
                @endforeach
            </div>
            <p class="mt-4 text-sm text-slate-500">{{ __($t.'.kokand_note') }}</p>
        </x-admin.card>

        <x-admin.card :title="__($t.'.limits_title')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-admin.input wire:model="form.max_total" inputmode="numeric" :label="__($t.'.max_total')" :hint="__($t.'.hints.max_total')" maxlength="20" />
                <x-admin.input wire:model="form.max_online_total" inputmode="numeric" :label="__($t.'.max_online_total')" :hint="__($t.'.hints.max_online_total')" maxlength="20" />
                <x-admin.input wire:model="form.max_delivery_charge" inputmode="numeric" :label="__($t.'.max_delivery_charge')" :hint="__($t.'.hints.max_delivery_charge')" maxlength="20" />
                <x-admin.input wire:model="form.max_manual_discount_percent" inputmode="numeric" :label="__($t.'.max_manual_discount_percent')" :hint="__($t.'.hints.max_manual_discount_percent')" maxlength="3" />
            </div>
        </x-admin.card>

        <x-admin.card :title="__($t.'.fiscal_title')" :description="__($t.'.fiscal_hint')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-admin.input wire:model="form.delivery_ikpu" inputmode="numeric" :label="__($t.'.delivery_ikpu')" :hint="__($t.'.hints.ikpu')" maxlength="17" />
                <x-admin.input wire:model="form.delivery_package_code" inputmode="numeric" :label="__($t.'.delivery_package_code')" :hint="__($t.'.hints.package_code')" maxlength="20" />
                <x-admin.toggle class="sm:col-span-2" wire:model="form.require_ikpu_online" :label="__($t.'.require_ikpu_online')" :description="__($t.'.hints.require_ikpu_online')" />
            </div>
        </x-admin.card>

        <x-admin.card :title="__($t.'.uzqr_title')" :description="__($t.'.uzqr_hint')">
            @unless ($passwordConfirmed)
                <p class="mb-4 flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="note" data-password-required>
                    <x-admin.icon name="lock" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.password_hint') }}
                </p>
            @endunless
            <div class="grid gap-4 sm:grid-cols-2">
                <x-admin.input wire:model="form.uzqr_recipient" :label="__($t.'.uzqr_recipient')" :hint="__($t.'.hints.uzqr_recipient')" maxlength="160" />
                <x-admin.image-upload model="form.uzqr" :file="$form->uzqr" :current-url="$uzqrPreview" remove-action="removeUzqr" :hint="__($t.'.hints.uzqr')" data-uzqr-upload />
            </div>
        </x-admin.card>
    </div>

    <x-admin.sticky-save-bar />
</form>
