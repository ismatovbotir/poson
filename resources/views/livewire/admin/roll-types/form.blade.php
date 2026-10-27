<form wire:submit="save" novalidate>
    <x-admin.page-header :title="$title" :back-href="route('admin.roll-types.index')" :back-label="__('admin.roll_types.title')">
        @if ($canSave)
            <x-slot:actions>
                <div class="hidden gap-2 lg:flex">
                    <x-admin.button variant="secondary" :href="route('admin.roll-types.index')" wire:navigate>{{ __('admin.common.cancel') }}</x-admin.button>
                    <x-admin.button type="submit" icon="check" loading="save">{{ __('admin.common.save') }}</x-admin.button>
                </div>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-3 lg:gap-6">
        <div class="min-w-0 space-y-4 lg:col-span-2 lg:space-y-6">
            <x-admin.card :title="__('admin.roll_types.sections.roll')" :description="__('admin.roll_types.form_help')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-admin.input wire:model.blur="form.labels_per_roll" type="number" min="1" max="{{ \App\Models\RollType::MAX_LABELS }}" inputmode="numeric" :label="__('admin.roll_types.fields.labels_per_roll')" required />
                    <x-admin.input wire:model.blur="form.core_diameter_mm" inputmode="decimal" maxlength="8" :label="__('admin.roll_types.fields.core_diameter_mm')" :hint="__('admin.roll_types.core_hint')" required />
                </div>
                @if (filled($form->labels_per_roll) && \App\Actions\Catalog\SaveRollType::normalizeCore($form->core_diameter_mm) !== null)
                    <p class="mt-4 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700" data-roll-preview>{{ __('admin.roll_types.preview') }}: <b class="font-semibold">{{ \App\Models\RollType::describe((int) $form->labels_per_roll, \App\Actions\Catalog\SaveRollType::normalizeCore($form->core_diameter_mm)) }}</b></p>
                @endif
                @if ($rollType && $productsCount > 0)
                    <p class="mt-3 flex items-start gap-1.5 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800" role="status" data-roll-type-in-use>
                        <x-admin.icon name="alert" size="size-4" class="shrink-0" />{{ __('admin.roll_types.in_use_notice', ['count' => $productsCount]) }}
                    </p>
                @endif
            </x-admin.card>
        </div>

        <aside class="min-w-0 space-y-4 lg:sticky lg:top-24 lg:self-start lg:space-y-6">
            <x-admin.card :title="__('admin.roll_types.sections.publish')">
                <div class="space-y-4">
                    <x-admin.toggle wire:model="form.is_active" :label="__('admin.roll_types.is_active')" :description="__('admin.roll_types.is_active_hint')" />
                    <x-admin.input wire:model="form.sort" type="number" min="0" inputmode="numeric" :label="__('admin.catalog.sort')" :hint="__('admin.catalog.sort_hint')" />
                </div>
            </x-admin.card>

            @if ($canDelete)
                <section class="rounded-2xl border border-red-200 bg-white p-4 sm:p-5">
                    <h2 class="text-sm font-semibold text-danger">{{ __('admin.catalog.danger_zone') }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ __('admin.roll_types.delete_help') }}</p>
                    <button
                        type="button"
                        x-on:click="$dispatch('confirm', {
                            title: @js(__('admin.roll_types.delete_title')),
                            body: @js(__('admin.roll_types.delete_body', ['name' => $rollType->label()])),
                            label: @js(__('admin.common.delete')),
                            component: $wire.$id, method: 'delete',
                        })"
                        class="mt-3 h-10 w-full rounded-lg border border-red-200 text-sm font-semibold text-danger hover:bg-red-50"
                    >{{ __('admin.common.delete') }}</button>
                </section>
            @endif
        </aside>
    </div>

    @if ($canSave)
        <x-admin.sticky-save-bar :cancel-href="route('admin.roll-types.index')" />
    @endif
</form>
