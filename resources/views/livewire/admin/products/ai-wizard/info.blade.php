{{-- AI wizard step "info" (plan 32 §5); variables from AiWizard::render(). --}}
<x-admin.card :title="__($t.'.info.title')" :description="__($t.'.info.help')" data-step="info">
    <form wire:submit="saveInfo" novalidate class="space-y-5">
        <div class="grid gap-4 sm:grid-cols-2">
            <x-admin.select wire:model="info.category_id" name="info.category_id" :label="__('admin.products.fields.category')" :options="$this->categoryOptions" :placeholder="__('admin.common.choose')" required />
            <x-admin.select wire:model="info.brand_id" name="info.brand_id" :label="__('admin.products.fields.brand')" :options="$this->brandOptions" :placeholder="__($t.'.info.no_brand')" />
            <x-admin.input wire:model="info.model" name="info.model" :label="__('admin.products.fields.model')" maxlength="255" autocomplete="off" />
            <x-admin.input wire:model="info.sku" name="info.sku" :label="__('admin.products.fields.sku')" maxlength="64" autocomplete="off" required class="font-mono" />
            <x-admin.select wire:model="info.type" name="info.type" :label="__('admin.products.fields.type')" :options="collect($types)->mapWithKeys(fn ($type) => [$type->value => $type->label()])->all()" required />
            <x-admin.select wire:model="info.sale_mode" name="info.sale_mode" :label="__('admin.products.fields.sale_mode')" :options="collect($saleModes)->mapWithKeys(fn ($mode) => [$mode->value => $mode->label()])->all()" required />
            <x-admin.input wire:model="info.price" name="info.price" inputmode="numeric" :label="__($t.'.info.price')" :hint="__($t.'.info.price_hint')" maxlength="24" autocomplete="off" />
        </div>
        <x-admin.textarea wire:model="notes" name="notes" :label="__($t.'.info.notes')" :hint="__($t.'.info.notes_hint', ['max' => $noteMax])" rows="4" maxlength="{{ $noteMax }}" />
        <p class="flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2.5 text-sm text-amber-800" role="note" data-pii-hint="notes">
            <x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.pii_hint') }}
        </p>
        <x-admin.field-error name="draft" data-draft-error />
        <x-admin.field-error name="info" />
        <div class="flex justify-end">
            <x-admin.button type="submit" icon="chevron-right" loading="saveInfo">{{ __($t.'.next') }}</x-admin.button>
        </div>
    </form>
</x-admin.card>
