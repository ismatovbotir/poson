<div>
    <x-admin.page-header :title="__('admin.dashboard.greeting', ['name' => $user->name])">
        <x-slot:breadcrumbs>{{ now()->locale(app()->getLocale())->isoFormat('dddd, D MMMM YYYY') }}</x-slot:breadcrumbs>
    </x-admin.page-header>

    <x-admin.card class="mt-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-600"><x-admin.icon name="shield" size="size-6" /></span>
            <div class="min-w-0">
                <p class="flex flex-wrap items-center gap-2 text-sm text-slate-600">
                    {{ __('admin.dashboard.signed_in_as') }}
                    @if ($role)
                        <x-admin.badge :color="$role->color()" data-role="{{ $role->value }}">{{ $role->label() }}</x-admin.badge>
                    @endif
                </p>
                <p class="mt-1 text-sm text-slate-500">{{ __('admin.dashboard.welcome') }}</p>
            </div>
        </div>
    </x-admin.card>

    @if ($this->cards !== [])
        <section class="mt-4 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4" aria-label="{{ __('admin.dashboard.stats_label') }}">
            @foreach ($this->cards as $card)
                <div wire:key="card-{{ $card['key'] }}" class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
                    <div class="flex items-start justify-between gap-2">
                        <p class="text-xs font-medium text-slate-500 sm:text-sm">{{ $card['label'] }}</p>
                        <span class="grid size-8 place-items-center rounded-lg bg-brand-50 text-brand-600"><x-admin.icon :name="$card['icon']" size="size-4" /></span>
                    </div>
                    <p class="mt-3 text-xl font-bold tracking-tight tabular-nums sm:text-[1.75rem]">{{ number_format($card['count'], 0, ',', ' ') }}</p>
                </div>
            @endforeach
        </section>
    @endif
</div>
