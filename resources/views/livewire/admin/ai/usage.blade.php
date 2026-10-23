@php
    $usd = fn (int $micro): string => \App\Livewire\Admin\Ai\Usage::usd($micro);
@endphp

<div>
    <x-admin.page-header :title="__('ai.usage.title')">
        <x-slot:breadcrumbs>{{ __('menu.groups.ai') }} <span class="text-slate-300">/</span> {{ __('ai.usage.title') }}</x-slot:breadcrumbs>
        @can('settings.integrations')
            <x-slot:actions>
                <x-admin.button variant="secondary" icon="plug" :href="route('admin.settings.integrations')" wire:navigate>{{ __('ai.usage.settings') }}</x-admin.button>
            </x-slot:actions>
        @endcan
    </x-admin.page-header>

    @if (! $enabled || ! $configured)
        <p class="mt-4 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800" role="status" data-ai-off>
            <x-admin.icon name="alert" size="size-5" class="shrink-0" />{{ ! $configured ? __('ai.usage.not_configured') : __('ai.usage.disabled') }}
        </p>
    @endif

    {{-- Totals --}}
    <div class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-2xl border border-slate-200 bg-white p-4" data-stat="today">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ai.usage.today') }}</p>
            <p class="mt-1 text-2xl font-bold tabular-nums">{{ $todayTotal }}</p>
            <p class="text-xs text-slate-500">{{ __('ai.usage.daily_limit', ['limit' => $dailyLimit]) }}</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4" data-stat="month">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ai.usage.month') }}</p>
            <p class="mt-1 text-2xl font-bold tabular-nums">{{ $monthTotal }}</p>
            <p class="text-xs text-slate-500">{{ __('ai.usage.requests') }}</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4" data-stat="cost">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ai.usage.month_cost') }}</p>
            <p class="mt-1 text-2xl font-bold tabular-nums">{{ $usd($monthCost) }}</p>
            <p class="text-xs text-slate-500">{{ $budget !== null ? __('ai.usage.budget', ['budget' => $usd($budget)]) : __('ai.usage.no_budget') }}</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4" data-stat="budget">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ai.usage.budget_used') }}</p>
            @if ($budget)
                @php($percent = min(100, (int) round($monthCost * 100 / $budget)))
                <p class="mt-1 text-2xl font-bold tabular-nums">{{ $percent }}%</p>
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100"><div @class(['h-full rounded-full', 'bg-brand-600' => $percent < 80, 'bg-amber-500' => $percent >= 80 && $percent < 100, 'bg-danger' => $percent >= 100]) style="width: {{ $percent }}%"></div></div>
            @else
                <p class="mt-1 text-2xl font-bold text-slate-400">{{ __('admin.common.empty_value') }}</p>
            @endif
        </div>
    </div>

    {{-- Per user --}}
    <x-admin.card :title="__('ai.usage.per_user')" :description="__('ai.usage.per_user_help', ['timezone' => $timezone])" :padding="false" class="mt-5">
        @if ($perUser->isEmpty())
            <p class="px-4 py-6 text-center text-sm text-slate-500 sm:px-6">{{ __('ai.usage.no_requests_month') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm" data-per-user>
                    <thead class="border-b border-slate-200 bg-slate-50/70 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="py-3 pl-5 pr-4">{{ __('ai.usage.columns.user') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('ai.usage.columns.today') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('ai.usage.columns.month') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('ai.usage.columns.tokens') }}</th>
                            <th class="py-3 pl-4 pr-5 text-right">{{ __('ai.usage.columns.cost') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($perUser as $row)
                            <tr wire:key="per-user-{{ $row->user_id ?? 'none' }}">
                                <td class="py-3 pl-5 pr-4 font-medium">{{ $row->name }}</td>
                                <td @class(['whitespace-nowrap px-4 py-3 text-right tabular-nums', 'font-semibold text-danger' => $row->today >= $dailyLimit])>{{ $row->today }} / {{ $dailyLimit }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ $row->month }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ number_format($row->tokens, 0, '.', ' ') }}</td>
                                <td class="py-3 pl-4 pr-5 text-right tabular-nums">{{ $usd($row->cost) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-admin.card>

    {{-- Request log --}}
    <h2 class="mt-8 text-base font-semibold">{{ __('ai.usage.log') }}</h2>
    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <x-admin.select wire:model.live="user" :label="__('ai.usage.columns.user')" :placeholder="__('ai.usage.filters.all_users')" :options="$this->userOptions" />
        <x-admin.select wire:model.live="status" :label="__('ai.usage.columns.status')" :placeholder="__('ai.usage.filters.all_statuses')" :options="collect(\App\Enums\AiRequestStatus::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" />
        <x-admin.select wire:model.live="feature" :label="__('ai.usage.columns.feature')" :placeholder="__('ai.usage.filters.all_features')" :options="collect(\App\Enums\AiFeature::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" />
    </div>

    <div class="relative mt-4">
        <div wire:loading.delay class="absolute inset-0 z-10 rounded-2xl bg-white/50" aria-hidden="true"></div>

        @if ($requests->isEmpty())
            <x-admin.empty-state icon="sparkles" :title="$filtering ? __('ai.usage.no_results') : __('ai.usage.empty_title')" :body="$filtering ? __('ai.usage.no_results_body') : __('ai.usage.empty_body')">
                @if ($filtering)
                    <x-admin.button variant="secondary" wire:click="clearFilters">{{ __('ai.usage.filters.clear') }}</x-admin.button>
                @endif
            </x-admin.empty-state>
        @else
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                <ul class="divide-y divide-slate-100" data-request-log>
                    @foreach ($requests as $item)
                        <li wire:key="ai-request-{{ $item->id }}" data-request="{{ $item->id }}">
                            <button type="button" wire:click="toggle({{ $item->id }})" aria-expanded="{{ $expanded === $item->id ? 'true' : 'false' }}" class="flex w-full flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-left text-sm hover:bg-slate-50/70 sm:px-5">
                                <span class="w-32 shrink-0 text-xs text-slate-500">{{ \App\Support\DateFormat::iso($item->created_at->copy()->setTimezone($timezone), 'D MMM, HH:mm') }}</span>
                                <span class="min-w-0 flex-1 truncate font-medium">{{ $item->user?->name ?? __('ai.usage.deleted_user') }}</span>
                                <span class="text-slate-600">{{ $item->feature->label() }}</span>
                                <span class="font-mono text-xs text-slate-500">{{ $item->field_key }}</span>
                                <x-admin.badge :color="$item->status->color()">{{ $item->status->label() }}</x-admin.badge>
                                @if ($item->error_code)
                                    <span class="text-xs text-danger">{{ $item->error_code->label() }}</span>
                                @endif
                                @if (! empty($item->brand_warnings))
                                    <x-admin.badge color="amber">{{ __('ai.usage.brand_warnings') }}</x-admin.badge>
                                @endif
                                <span class="w-20 text-right tabular-nums text-slate-600">{{ $usd($item->cost_micro_usd) }}</span>
                                <x-admin.icon name="chevron-down" size="size-4" @class(['shrink-0 text-slate-400 transition-transform', 'rotate-180' => $expanded === $item->id]) />
                            </button>
                            @if ($expanded === $item->id && ($detail = $this->expandedRequest))
                                <div class="space-y-3 border-t border-slate-100 bg-slate-50/60 px-4 py-4 text-sm sm:px-5" data-request-detail>
                                    <dl class="grid grid-cols-2 gap-2 text-xs text-slate-600 sm:grid-cols-4">
                                        <div><dt class="font-semibold">{{ __('ai.usage.columns.model') }}</dt><dd class="font-mono">{{ $detail->model ?? __('admin.common.empty_value') }}</dd></div>
                                        <div><dt class="font-semibold">{{ __('ai.usage.columns.tokens') }}</dt><dd class="tabular-nums">{{ $detail->input_tokens }} / {{ $detail->output_tokens }}</dd></div>
                                        <div><dt class="font-semibold">{{ __('ai.usage.columns.duration') }}</dt><dd class="tabular-nums">{{ $detail->duration_ms !== null ? __('ai.usage.seconds', ['value' => number_format($detail->duration_ms / 1000, 1)]) : __('admin.common.empty_value') }}</dd></div>
                                        <div><dt class="font-semibold">{{ __('ai.usage.columns.source_locale') }}</dt><dd>{{ $detail->source_locale ? strtoupper($detail->source_locale) : __('admin.common.empty_value') }}</dd></div>
                                    </dl>
                                    @if ($detail->pruned_at)
                                        <p class="text-xs text-slate-500">{{ __('ai.usage.pruned') }}</p>
                                    @else
                                        <div>
                                            <p class="text-xs font-semibold text-slate-600">{{ __('ai.usage.prompt') }}</p>
                                            <p class="mt-1 whitespace-pre-line rounded-lg bg-white p-3 text-slate-800 ring-1 ring-slate-200" data-request-prompt>{{ $detail->prompt }}</p>
                                        </div>
                                        @if (is_array($detail->response))
                                            <div class="grid gap-2 lg:grid-cols-3">
                                                @foreach ($detail->response as $locale => $text)
                                                    <div class="rounded-lg bg-white p-3 ring-1 ring-slate-200" lang="{{ $locale }}">
                                                        <p class="text-[11px] font-bold uppercase text-slate-500">{{ $locale }}</p>
                                                        <p class="mt-1 whitespace-pre-line text-slate-800">{{ \App\Services\Ai\BrandGuard::highlight(html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8')) }}</p>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    @endif
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="mt-4">{{ $requests->links() }}</div>
        @endif
    </div>
</div>
