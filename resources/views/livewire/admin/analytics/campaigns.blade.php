@php
    use App\Actions\Analytics\ExportOfflineConversions;
    use App\Support\Analytics\CampaignReport;
    use App\Support\Money;
    use App\Support\NumberFormat;

    $t = 'analytics.campaigns';
    $label = fn (string $value): string => match ($value) {
        CampaignReport::NONE => __($t.'.none'),
        CampaignReport::OTHER => __($t.'.other'),
        default => $value,
    };
@endphp

{{-- Analytics → Campaigns (plan 40). UTM values are escaped by Blade and cut by CampaignReport (YD-13). --}}
<div>
    <x-admin.page-header :title="__($t.'.title')" :subtitle="__($t.'.subtitle')">
        <x-slot:breadcrumbs>{{ __('menu.groups.dashboard') }} <span class="text-slate-300">/</span> {{ __('analytics.menu.campaigns') }}</x-slot:breadcrumbs>
        <x-slot:actions>
            @include('livewire.admin.analytics.partials.range-tabs')
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mt-4">
        <x-admin.toggle wire:model.live="direct" :label="__($t.'.direct_only')" :description="__($t.'.direct_only_hint')" />
    </div>

    <div class="relative mt-4">
        <div wire:loading.delay class="absolute inset-0 z-10 rounded-2xl bg-canvas/50" aria-hidden="true"></div>

        <x-admin.card :title="__($t.'.table_title')" :description="__('analytics.common.last_days', ['count' => $days])" :padding="false">
            @if ($rows === [])
                <p class="px-4 py-6 text-sm text-slate-500 sm:px-6" data-empty>{{ __($t.'.empty') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm" data-campaigns>
                        <thead class="border-b border-slate-200 bg-slate-50/70 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="py-2.5 pl-4 pr-3 sm:pl-6">{{ __($t.'.columns.source') }}</th>
                                <th class="px-3 py-2.5">{{ __($t.'.columns.medium') }}</th>
                                <th class="px-3 py-2.5">{{ __($t.'.columns.campaign') }}</th>
                                <th class="px-3 py-2.5 text-right">{{ __($t.'.columns.leads') }}</th>
                                <th class="px-3 py-2.5 text-right">{{ __($t.'.columns.leads_with_click') }}</th>
                                <th class="px-3 py-2.5 text-right">{{ __($t.'.columns.orders') }}</th>
                                <th class="px-3 py-2.5 text-right">{{ __($t.'.columns.orders_value') }}</th>
                                <th class="px-3 py-2.5 text-right">{{ __($t.'.columns.paid_value') }}</th>
                                <th class="px-3 py-2.5 text-right">{{ __($t.'.columns.quotes') }}</th>
                                <th class="py-2.5 pl-3 pr-4 text-right sm:pr-6">{{ __($t.'.columns.quotes_value') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($rows as $index => $row)
                                <tr wire:key="campaign-{{ $index }}-{{ md5($row['source'].'|'.$row['medium'].'|'.$row['campaign']) }}" data-campaign-row>
                                    <td class="max-w-48 truncate py-2.5 pl-4 pr-3 font-medium sm:pl-6" title="{{ $label($row['source']) }}">{{ $label($row['source']) }}</td>
                                    <td class="max-w-32 truncate px-3 py-2.5" title="{{ $label($row['medium']) }}">{{ $label($row['medium']) }}</td>
                                    <td class="max-w-64 truncate px-3 py-2.5" title="{{ $label($row['campaign']) }}">{{ $label($row['campaign']) }}</td>
                                    <td class="px-3 py-2.5 text-right tabular-nums">{{ NumberFormat::format($row['leads']) }}</td>
                                    <td class="px-3 py-2.5 text-right tabular-nums">{{ NumberFormat::format($row['leads_with_click']) }}</td>
                                    <td class="px-3 py-2.5 text-right tabular-nums">{{ NumberFormat::format($row['orders']) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums">{{ Money::format($row['orders_value']) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums">{{ Money::format($row['paid_value']) }}</td>
                                    <td class="px-3 py-2.5 text-right tabular-nums">{{ NumberFormat::format($row['quotes']) }}</td>
                                    <td class="whitespace-nowrap py-2.5 pl-3 pr-4 text-right tabular-nums sm:pr-6">{{ Money::format($row['quotes_value']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t border-slate-200 bg-slate-50/70 font-semibold">
                            <tr data-campaign-totals>
                                <td class="py-2.5 pl-4 pr-3 sm:pl-6" colspan="3">{{ __($t.'.totals') }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums" data-total="leads">{{ NumberFormat::format($totals['leads']) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums" data-total="leads_with_click">{{ NumberFormat::format($totals['leads_with_click']) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums" data-total="orders">{{ NumberFormat::format($totals['orders']) }}</td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums" data-total="orders_value">{{ Money::format($totals['orders_value']) }}</td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums" data-total="paid_value">{{ Money::format($totals['paid_value']) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums" data-total="quotes">{{ NumberFormat::format($totals['quotes']) }}</td>
                                <td class="whitespace-nowrap py-2.5 pl-3 pr-4 text-right tabular-nums sm:pr-6" data-total="quotes_value">{{ Money::format($totals['quotes_value']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </x-admin.card>
        <p class="mt-2 text-xs text-slate-500">{{ __($t.'.notes') }}</p>
    </div>

    @if ($canExport)
        <div class="mt-6 grid grid-cols-1 gap-4 xl:grid-cols-2">
            {{-- Ad click id switch (plan 40 §11, YD-8): Admin only, recent password, audited. --}}
            <x-admin.card :title="__($t.'.click_ids.title')" :description="__($t.'.click_ids.hint')" data-click-ids-switch>
                <x-slot:actions>
                    <x-admin.badge :color="$switchOn ? 'brand' : 'amber'" dot data-click-ids-state="{{ $switchOn ? 'on' : 'off' }}">
                        {{ $switchOn ? __($t.'.click_ids.state_on') : __($t.'.click_ids.state_off') }}
                    </x-admin.badge>
                </x-slot:actions>

                <form wire:submit="saveClickIds" class="space-y-4">
                    @unless ($passwordConfirmed)
                        <p class="flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="note" data-password-required>
                            <x-admin.icon name="lock" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.click_ids.password_hint') }}
                        </p>
                    @endunless

                    <x-admin.toggle wire:model="clickIdsEnabled" :label="__($t.'.click_ids.label')" :description="__($t.'.click_ids.help')" />
                    @error('clickIdsEnabled')
                        <p class="text-sm text-danger" data-click-ids-error>{{ $message }}</p>
                    @enderror

                    <x-admin.button type="submit" size="sm">{{ __($t.'.click_ids.save') }}</x-admin.button>
                </form>

                @if ($audits !== null && $audits->isNotEmpty())
                    <ul class="mt-4 divide-y divide-slate-100 border-t border-slate-100 text-sm">
                        @foreach ($audits as $audit)
                            <li wire:key="analytics-audit-{{ $audit->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2" data-audit-row>
                                <span class="flex min-w-0 items-center gap-2"><x-admin.badge color="slate">{{ $audit->action->label() }}</x-admin.badge><span class="truncate font-mono text-xs text-slate-500">{{ $audit->key }}</span></span>
                                <span class="text-xs text-slate-500">{{ $audit->user?->name ?? __('admin.common.empty_value') }} · {{ $audit->created_at->format('d.m.Y H:i') }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-admin.card>

            {{-- Offline conversions CSV (plan 40 §3, YD-10): a plain POST with CSRF, never a GET link. --}}
            <x-admin.card :title="__($t.'.export.title')" :description="__($t.'.export.hint', ['days' => $export['days']])" data-offline-export>
                @if (! $switchOn)
                    <p class="flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="note" data-export-disabled>
                        <x-admin.icon name="info" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.export.disabled') }}
                    </p>
                @else
                    <form method="POST" action="{{ route('admin.analytics.offline-conversions') }}" class="space-y-4" data-export-form>
                        @csrf
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <label class="block text-sm">
                                <span class="font-medium text-slate-900">{{ __($t.'.export.from') }}</span>
                                <input type="date" name="from" value="{{ $export['from'] }}" min="{{ $export['min'] }}" max="{{ $export['max'] }}" required class="mt-1 h-10 w-full rounded-lg border border-slate-200 px-3 text-sm outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15">
                            </label>
                            <label class="block text-sm">
                                <span class="font-medium text-slate-900">{{ __($t.'.export.to') }}</span>
                                <input type="date" name="to" value="{{ $export['to'] }}" min="{{ $export['min'] }}" max="{{ $export['max'] }}" required class="mt-1 h-10 w-full rounded-lg border border-slate-200 px-3 text-sm outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15">
                            </label>
                        </div>
                        <p class="text-xs text-slate-500" data-last-export>
                            {{ $export['last'] !== null ? __($t.'.export.last', ['date' => \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $export['last'])->format('d.m.Y')]) : __($t.'.export.never') }}
                        </p>

                        <fieldset>
                            <legend class="text-sm font-medium text-slate-900">{{ __($t.'.export.id_type') }}</legend>
                            <div class="mt-2 flex flex-wrap gap-4 text-sm">
                                <label class="flex items-center gap-2"><input type="radio" name="id_type" value="{{ ExportOfflineConversions::ID_CLIENT }}" checked class="text-brand-600"> {{ __($t.'.export.id_client') }}</label>
                                <label class="flex items-center gap-2"><input type="radio" name="id_type" value="{{ ExportOfflineConversions::ID_YCLID }}" class="text-brand-600"> {{ __($t.'.export.id_yclid') }}</label>
                            </div>
                        </fieldset>

                        <fieldset>
                            <legend class="text-sm font-medium text-slate-900">{{ __($t.'.export.goals') }}</legend>
                            <div class="mt-2 flex flex-wrap gap-4 text-sm">
                                @foreach ($export['goals'] as $goal)
                                    <label class="flex items-center gap-2" wire:key="export-goal-{{ $goal->value }}"><input type="checkbox" name="goals[]" value="{{ $goal->value }}" checked class="rounded text-brand-600"> {{ $goal->label() }} <span class="font-mono text-xs text-slate-500">{{ $goal->value }}</span></label>
                                @endforeach
                            </div>
                        </fieldset>

                        <p class="text-xs text-slate-500">{{ __($t.'.export.dedupe') }}</p>

                        <x-admin.button type="submit" size="sm">{{ __($t.'.export.submit') }}</x-admin.button>
                    </form>
                @endif
            </x-admin.card>
        </div>
    @endif
</div>
