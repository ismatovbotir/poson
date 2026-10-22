<div>
    <x-admin.page-header :title="__('admin.dashboard.greeting', ['name' => $user->name])">
        <x-slot:breadcrumbs>{{ \App\Support\DateFormat::iso(now(), 'dddd, D MMMM YYYY') }}</x-slot:breadcrumbs>
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

    @if ($this->telegramFailures)
        <section class="mt-4 flex flex-col gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-amber-900 sm:flex-row sm:items-start sm:p-5" role="alert" data-telegram-failures>
            <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-white text-amber-600"><x-admin.icon name="telegram" /></span>
            <div class="min-w-0 flex-1">
                <h2 class="text-sm font-semibold">{{ __('crm.dashboard.telegram_failures.title') }}</h2>
                <p class="mt-1 text-sm">{{ __('crm.dashboard.telegram_failures.body', ['count' => $this->telegramFailures['count']]) }}</p>
                <p class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-sm">
                    @foreach ($this->telegramFailures['leads'] as $failedLead)
                        <a href="{{ route('admin.leads.show', $failedLead) }}" wire:navigate wire:key="tg-failed-{{ $failedLead->id }}" class="font-mono font-semibold underline hover:no-underline">{{ $failedLead->reference() }}</a>
                    @endforeach
                </p>
            </div>
            <x-admin.button size="sm" variant="secondary" :href="route('admin.settings.integrations').'#section-telegram'" wire:navigate>{{ __('crm.dashboard.telegram_failures.settings') }}</x-admin.button>
        </section>
    @endif

    @if ($this->leadCards !== [])
        <section class="mt-4 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4" aria-label="{{ __('crm.dashboard.leads_label') }}" data-lead-cards>
            @foreach ($this->leadCards as $card)
                <a href="{{ $card['href'] }}" wire:navigate wire:key="card-{{ $card['key'] }}" data-card="{{ $card['key'] }}" class="rounded-2xl border border-slate-200 bg-white p-4 transition hover:border-brand-300 hover:shadow-sm sm:p-5">
                    <div class="flex items-start justify-between gap-2">
                        <p class="text-xs font-medium text-slate-500 sm:text-sm">{{ $card['label'] }}</p>
                        <span @class(['grid size-8 place-items-center rounded-lg', 'bg-amber-50 text-amber-600' => $card['key'] === 'leads_unassigned' && $card['count'] > 0, 'bg-brand-50 text-brand-600' => ! ($card['key'] === 'leads_unassigned' && $card['count'] > 0)])><x-admin.icon :name="$card['icon']" size="size-4" /></span>
                    </div>
                    <p class="mt-3 text-xl font-bold tracking-tight tabular-nums sm:text-[1.75rem]" data-count>{{ number_format($card['count'], 0, ',', ' ') }}</p>
                </a>
            @endforeach
        </section>

        <x-admin.card class="mt-4" :title="__('crm.dashboard.recent')" :padding="false" data-recent-leads>
            <x-slot:actions>
                <a href="{{ route('admin.leads.index') }}" wire:navigate class="text-sm font-semibold text-brand-600 hover:text-brand-700">{{ __('crm.dashboard.all_leads') }}</a>
            </x-slot:actions>
            @if ($this->recentLeads->isEmpty())
                <p class="px-4 py-6 text-center text-sm text-slate-500 sm:px-6">{{ __('crm.leads.empty_title') }}</p>
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($this->recentLeads as $lead)
                        <li wire:key="recent-{{ $lead->id }}">
                            <a href="{{ route('admin.leads.show', $lead) }}" wire:navigate class="flex items-start gap-3 px-4 py-3 hover:bg-slate-50 sm:px-6">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold">{{ $lead->company ?: $lead->name }}</span>
                                    <span class="block truncate text-sm text-slate-500">@include('livewire.admin.leads.partials.need', ['lead' => $lead])</span>
                                    <span class="mt-1 block text-xs text-slate-400"><span class="font-mono">{{ $lead->reference() }}</span> · {{ \App\Support\DateFormat::iso($lead->created_at->copy()->setTimezone(\App\Models\Lead::TIMEZONE), 'D MMM, HH:mm') }}</span>
                                </span>
                                <x-admin.badge :color="$lead->status->color()" dot>{{ $lead->status->label() }}</x-admin.badge>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-admin.card>
    @endif

    @if ($this->cards !== [])
        <section class="mt-4 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4" aria-label="{{ __('admin.dashboard.stats_label') }}">
            @foreach ($this->cards as $card)
                <{{ isset($card['href']) ? 'a' : 'div' }} @isset($card['href']) href="{{ $card['href'] }}" wire:navigate @endisset wire:key="card-{{ $card['key'] }}" data-card="{{ $card['key'] }}" @class(['rounded-2xl border border-slate-200 bg-white p-4 sm:p-5', 'transition hover:border-brand-300 hover:shadow-sm' => isset($card['href'])])>
                    <div class="flex items-start justify-between gap-2">
                        <p class="text-xs font-medium text-slate-500 sm:text-sm">{{ $card['label'] }}</p>
                        <span @class(['grid size-8 place-items-center rounded-lg', 'bg-amber-50 text-amber-600' => $card['key'] === 'products_incomplete' && $card['count'] > 0, 'bg-brand-50 text-brand-600' => ! ($card['key'] === 'products_incomplete' && $card['count'] > 0)])><x-admin.icon :name="$card['icon']" size="size-4" /></span>
                    </div>
                    <p class="mt-3 text-xl font-bold tracking-tight tabular-nums sm:text-[1.75rem]">{{ number_format($card['count'], 0, ',', ' ') }}</p>
                </{{ isset($card['href']) ? 'a' : 'div' }}>
            @endforeach
        </section>
    @endif
</div>
