@php
    $t = 'api.sync';
    $tz = \App\Models\PriceSyncLog::TIMEZONE;
@endphp

{{-- Catalog → Price sync (plan 09): the price API batch log. --}}
<div>
    <x-admin.page-header :title="__($t.'.title')" :subtitle="__($t.'.subtitle')">
        <x-slot:breadcrumbs>{{ __('menu.groups.catalog') }} <span class="text-slate-300">/</span> {{ __($t.'.title') }}</x-slot:breadcrumbs>
        @can('viewAny', \App\Models\ApiToken::class)
            <x-slot:actions>
                <x-admin.button variant="secondary" icon="shield" :href="route('admin.settings.api-tokens')" wire:navigate>{{ __('api.tokens.title') }}</x-admin.button>
            </x-slot:actions>
        @endcan
    </x-admin.page-header>

    <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-admin.select wire:model.live="token" :label="__($t.'.columns.token')" :placeholder="__($t.'.filters.all_tokens')" :options="$this->tokenOptions" />
        <x-admin.select wire:model.live="status" :label="__($t.'.columns.status')" :placeholder="__($t.'.filters.all_statuses')" :options="collect(\App\Enums\PriceSyncStatus::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" />
        <x-admin.input wire:model.live="from" type="date" :label="__($t.'.filters.from')" />
        <x-admin.input wire:model.live="to" type="date" :label="__($t.'.filters.to')" />
    </div>

    <div class="relative mt-4">
        <div wire:loading.delay class="absolute inset-0 z-10 rounded-2xl bg-white/50" aria-hidden="true"></div>

        @if ($logs->isEmpty())
            <x-admin.empty-state icon="upload" :title="$filtering ? __($t.'.no_results') : __($t.'.empty_title')" :body="$filtering ? __($t.'.no_results_body') : __($t.'.empty_body')">
                @if ($filtering)
                    <x-admin.button variant="secondary" wire:click="clearFilters">{{ __($t.'.filters.clear') }}</x-admin.button>
                @endif
            </x-admin.empty-state>
        @else
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                <ul class="divide-y divide-slate-100" data-sync-log>
                    @foreach ($logs as $log)
                        <li wire:key="price-sync-{{ $log->id }}" data-sync="{{ $log->id }}">
                            <a href="{{ route('admin.price-sync.show', $log) }}" wire:navigate class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-sm hover:bg-slate-50/70 sm:px-5">
                                <span class="w-32 shrink-0 text-xs text-slate-500">{{ \App\Support\DateFormat::iso($log->created_at->copy()->setTimezone($tz), 'D MMM, HH:mm:ss') }}</span>
                                <span class="font-mono text-xs text-slate-400">#{{ $log->id }}</span>
                                <span class="min-w-0 flex-1 truncate font-medium">{{ $log->token_name ?? __($t.'.no_token') }}</span>
                                <x-admin.badge :color="$log->status->color()">{{ $log->status->label() }}</x-admin.badge>
                                @if ($log->error)
                                    <span class="text-xs text-danger">{{ \Illuminate\Support\Facades\Lang::has('api.errors.'.$log->error) ? __('api.errors.'.$log->error) : $log->error }} <code class="font-mono text-[11px] text-red-400">{{ $log->error }}</code></span>
                                @else
                                    <span class="flex flex-wrap gap-x-3 text-xs tabular-nums text-slate-600">
                                        <span>{{ __($t.'.counts.received') }}: {{ $log->received }}</span>
                                        <span class="text-brand-700">{{ __($t.'.counts.updated') }}: {{ $log->updated }}</span>
                                        <span>{{ __($t.'.counts.unchanged') }}: {{ $log->unchanged }}</span>
                                        <span @class(['font-semibold text-amber-700' => $log->unknown > 0])>{{ __($t.'.counts.unknown') }}: {{ $log->unknown }}</span>
                                        <span @class(['font-semibold text-danger' => $log->invalid > 0])>{{ __($t.'.counts.invalid') }}: {{ $log->invalid }}</span>
                                    </span>
                                @endif
                                <x-admin.icon name="chevron-right" size="size-4" class="shrink-0 text-slate-400" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="mt-4">{{ $logs->links() }}</div>
        @endif
    </div>
</div>
