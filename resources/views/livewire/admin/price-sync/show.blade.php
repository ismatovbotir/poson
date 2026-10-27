@php
    $t = 'api.sync';
    $tz = \App\Models\PriceSyncLog::TIMEZONE;
    $money = fn (?int $tiyin) => $tiyin === null ? __('admin.common.empty_value') : \App\Support\Money::format($tiyin);
    $statusColors = ['updated' => 'brand', 'unchanged' => 'slate', 'unknown' => 'amber', 'invalid' => 'red'];
    $unknownCodes = array_values((array) $log->unknown_codes);
    // Translated label of a machine error code; the raw code stays visible next to it.
    $errorLabel = fn (string $code) => \Illuminate\Support\Facades\Lang::has('api.errors.'.$code) ? __('api.errors.'.$code) : $code;
@endphp

{{-- One price API batch (plan 09). --}}
<div>
    <x-admin.page-header :title="__($t.'.show_title', ['id' => $log->id])" :back-href="route('admin.price-sync.index')" :back-label="__($t.'.title')" />

    <div class="mt-2 flex flex-wrap items-center gap-2 text-sm text-slate-500">
        <x-admin.badge :color="$log->status->color()">{{ $log->status->label() }}</x-admin.badge>
        <span>{{ \App\Support\DateFormat::iso($log->created_at->copy()->setTimezone($tz), 'D MMMM YYYY, HH:mm:ss') }}</span>
        <span>· {{ __($t.'.columns.token') }}: <b class="font-semibold text-slate-700">{{ $log->token_name ?? __($t.'.no_token') }}</b></span>
        @if ($log->ip)<span class="font-mono text-xs">· {{ $log->ip }}</span>@endif
        @if ($log->duration_ms !== null)<span>· {{ __($t.'.duration', ['ms' => $log->duration_ms]) }}</span>@endif
    </div>

    @if ($log->error)
        <p class="mt-4 flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-danger" role="status" data-sync-error>
            <x-admin.icon name="alert" size="size-5" class="shrink-0" />
            <span>{{ __($t.'.rejected') }} {{ $errorLabel($log->error) }} <code class="font-mono text-xs opacity-75">{{ $log->error }}</code></span>
        </p>
    @endif

    <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-5">
        @foreach (['received', 'updated', 'unchanged', 'unknown', 'invalid'] as $count)
            <div class="rounded-2xl border border-slate-200 bg-white p-4" data-count="{{ $count }}">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __($t.'.counts.'.$count) }}</p>
                <p class="mt-1 text-2xl font-bold tabular-nums">{{ $log->{$count} }}</p>
            </div>
        @endforeach
    </div>

    @if ($unknownCodes !== [])
        <x-admin.card :title="__($t.'.unknown_codes')" :description="__($t.'.unknown_codes_help')" class="mt-5">
            <ul class="flex flex-wrap gap-2" data-unknown-codes>
                @foreach ($unknownCodes as $code)
                    <li class="rounded-md bg-amber-50 px-2 py-1 font-mono text-xs text-amber-800 ring-1 ring-inset ring-amber-600/20">{{ $code }}</li>
                @endforeach
            </ul>
        </x-admin.card>
    @endif

    @if ($results !== [])
        <h2 class="mt-8 text-base font-semibold">{{ __($t.'.items') }}</h2>
        <div class="mt-3 overflow-x-auto rounded-2xl border border-slate-200 bg-white">
            <table class="w-full text-left text-sm" data-sync-items>
                <thead class="border-b border-slate-200 bg-slate-50/70 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="py-3 pl-5 pr-4">#</th>
                        <th class="px-4 py-3">{{ __($t.'.columns.code') }}</th>
                        <th class="px-4 py-3">{{ __($t.'.columns.product') }}</th>
                        <th class="px-4 py-3">{{ __($t.'.columns.status') }}</th>
                        @foreach (['retail', 'dealer', 'wholesale'] as $level)
                            <th class="px-4 py-3 text-right">{{ __('enums.price_level.'.$level) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($results as $index => $row)
                        @php($product = isset($row['product_id']) ? $products->get($row['product_id']) : null)
                        <tr wire:key="sync-item-{{ $index }}" data-item-status="{{ $row['status'] ?? '' }}">
                            <td class="py-3 pl-5 pr-4 tabular-nums text-slate-400">{{ $index + 1 }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-mono text-xs">{{ $row['code'] ?? __('admin.common.empty_value') }}</td>
                            <td class="px-4 py-3">
                                @if ($product)
                                    <a href="{{ route('admin.products.edit', $product) }}" wire:navigate class="hover:text-brand-700">{{ $product->name }}</a>
                                    @if ($product->trashed())<x-admin.badge color="muted">{{ __($t.'.in_trash') }}</x-admin.badge>@endif
                                @else
                                    <span class="text-slate-400">{{ __('admin.common.empty_value') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <x-admin.badge :color="$statusColors[$row['status'] ?? ''] ?? 'slate'">{{ __($t.'.item_status.'.($row['status'] ?? 'invalid')) }}</x-admin.badge>
                                @if (! empty($row['errors']))
                                    <ul class="mt-1 space-y-0.5 text-xs text-danger" data-item-errors>
                                        @foreach ((array) $row['errors'] as $code)
                                            <li>{{ $errorLabel((string) $code) }} <code class="font-mono text-[11px] text-red-400">{{ $code }}</code></li>
                                        @endforeach
                                    </ul>
                                @endif
                            </td>
                            @foreach (['retail', 'dealer', 'wholesale'] as $level)
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">
                                    @if (($row['status'] ?? null) === 'updated')
                                        <span class="block text-xs text-slate-400">{{ $money($row['old'][$level] ?? null) }}</span>
                                        <span class="block font-semibold">{{ $money($row['new'][$level] ?? null) }}</span>
                                    @else
                                        <span class="text-slate-300">{{ __('admin.common.empty_value') }}</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
