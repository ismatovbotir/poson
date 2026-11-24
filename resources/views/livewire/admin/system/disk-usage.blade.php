@php
    use App\Enums\DiskMeasureStatus;
    use App\Enums\DiskRunStatus;
    use App\Enums\DiskTarget;
    use Illuminate\Support\Number;

    $t = 'monitoring.disk';
    $when = fn ($date) => $date ? $date->timezone('Asia/Tashkent')->format('d.m.Y H:i') : __('admin.common.empty_value');
    $size = fn (int $bytes): string => Number::fileSize($bytes, 1);
    $delta = fn (?int $bytes): string => $bytes === null ? __('admin.common.empty_value') : ($bytes === 0 ? '0' : ($bytes > 0 ? '+' : '−').Number::fileSize(abs($bytes), 1));
    $deltaClass = fn (?int $bytes): string => $bytes === null || $bytes === 0 ? 'text-slate-500' : ($bytes > 0 ? 'text-amber-700' : 'text-brand-700');
    $buckets = DiskTarget::buckets();
    $step = $run->bucket === null ? null : array_search($run->bucket, $buckets, true);
    $percent = $run->status === DiskRunStatus::Queued || $step === false || $step === null ? 0 : (int) round(($step + 1) / count($buckets) * 100);
    $failed = $run->status === DiskRunStatus::Failed;
    $coolMinutes = (int) ceil($run->cooldownLeft() / 60);
@endphp

<div data-disk-usage @if ($polling) wire:poll.2s="poll" data-disk-polling @endif>
    <x-admin.card class="mt-5" :title="__($t.'.title')" :description="__($t.'.subtitle')">
        <x-slot:actions>
            <x-admin.button size="sm" variant="secondary" icon="restore" wire:click="refresh" loading="refresh" data-disk-refresh>{{ __($t.'.refresh') }}</x-admin.button>
            @if ($canManage)
                <x-admin.button size="sm" icon="chart" wire:click="measure" loading="measure" :disabled="$run->isActive() || $coolMinutes > 0" data-disk-measure>{{ __($t.'.measure_now') }}</x-admin.button>
            @endif
        </x-slot:actions>

        {{-- Progress / outcome of the latest run --}}
        @if ($run->isActive() && ! $pollExpired)
            <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900" role="status" data-disk-progress="{{ $run->status->value }}">
                @if ($run->status === DiskRunStatus::Queued)
                    <p class="font-semibold">{{ __($t.'.queued') }}</p>
                    <p class="mt-1 text-xs">{{ __($t.'.queued_hint') }}</p>
                @else
                    <p class="font-semibold">{{ $step !== null && $step !== false ? __($t.'.running', ['bucket' => $run->bucket->label()]) : __($t.'.running_start') }}</p>
                    <p class="mt-1 text-xs" data-disk-counters>{{ __($t.'.counters', ['files' => number_format($run->files, 0, '.', ' '), 'size' => $size($run->bytes)]) }}</p>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-sky-100" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}">
                        <div class="h-full rounded-full bg-sky-500 transition-all" style="width: {{ $percent }}%"></div>
                    </div>
                    <ol class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                        @foreach ($buckets as $index => $bucket)
                            <li @class(['font-semibold text-sky-900' => $step === $index, 'text-sky-700' => $step !== false && $step !== null && $index < $step, 'text-sky-400' => $step === false || $step === null || $index > $step]) wire:key="step-{{ $bucket->value }}">{{ $bucket->label() }}</li>
                        @endforeach
                    </ol>
                @endif
            </div>
        @elseif ($pollExpired)
            <p class="flex flex-wrap items-center gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="note" data-disk-poll-stopped>
                {{ __($t.'.poll_stopped') }}
                <x-admin.button size="sm" variant="secondary" wire:click="checkAgain">{{ __($t.'.check_again') }}</x-admin.button>
            </p>
        @elseif ($failed)
            <p class="flex gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-danger" role="alert" data-disk-failed>
                <x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ $run->error?->label() ?? __($t.'.failed') }}
            </p>
        @endif

        @if ($run->status === DiskRunStatus::Done && $run->error?->value === 'budget')
            <p class="mt-3 flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="note" data-disk-budget>
                <x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ $run->error->label() }}
            </p>
        @endif

        @if (! $run->isActive() && $coolMinutes > 0 && $canManage)
            <p class="mt-3 text-xs text-slate-500" data-disk-cooldown>{{ __($t.'.cooldown', ['minutes' => $coolMinutes]) }}</p>
        @endif

        <p class="mt-3 flex gap-2 text-xs text-slate-500" data-disk-quota-note><x-admin.icon name="info" size="size-4" class="shrink-0" />{{ __($t.'.quota_note') }}</p>
        <p class="mt-2 flex gap-2 text-xs text-slate-500" data-disk-alert-note><x-admin.icon name="info" size="size-4" class="shrink-0" />{{ __($t.'.alerts_note') }}</p>

        @if ($activeRun)
            {{-- History is not read while a run is active (the poll stays cheap). --}}
        @elseif ($total === null)
            <p class="mt-5 rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500" data-disk-empty>{{ __($t.'.empty') }}</p>
        @else
            {{-- Project total --}}
            <dl class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-disk-total>
                <div class="rounded-xl border border-slate-200 p-4">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __($t.'.total') }}</dt>
                    <dd class="mt-2">
                        <p class="text-2xl font-semibold text-slate-900" data-disk-total-size>@if ($total['status'] === DiskMeasureStatus::Partial)<span class="text-sm font-medium text-amber-700">{{ __($t.'.at_least') }}</span> @endif{{ $size($total['bytes']) }}</p>
                        <p class="mt-1 text-sm text-slate-600">{{ __($t.'.files', ['count' => number_format($total['files'], 0, '.', ' ')]) }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ __($t.'.last_measured') }}: <span class="font-semibold text-slate-700" data-disk-measured-at>{{ $when($total['measured_at']) }}</span></p>
                    </dd>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __($t.'.change') }}</dt>
                    <dd class="mt-2 space-y-1 text-sm">
                        @foreach ($total['changes'] as $back => $change)
                            <p class="flex justify-between gap-3"><span class="text-slate-600">{{ __($t.'.days', ['count' => $back]) }}@if ($total['bases'][$back] ?? null) <span class="text-xs text-slate-400">({{ __($t.'.vs', ['date' => \Illuminate\Support\Carbon::parse($total['bases'][$back])->format('d.m')]) }})</span>@endif</span><span @class(['font-semibold', $deltaClass($change)])>{{ $delta($change) }}</span></p>
                        @endforeach
                    </dd>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __($t.'.limits') }}</dt>
                    <dd class="mt-2 space-y-1 text-sm" data-disk-limits>
                        <p class="flex justify-between gap-3"><span class="text-slate-600">{{ __($t.'.warn') }}</span><span class="font-semibold text-amber-700">{{ $limits['warn_gb'] }} GB</span></p>
                        <p class="flex justify-between gap-3"><span class="text-slate-600">{{ __($t.'.critical') }}</span><span class="font-semibold text-danger">{{ $limits['critical_gb'] }} GB</span></p>
                    </dd>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __($t.'.trend', ['days' => $days]) }}</dt>
                    <dd class="mt-3"><x-admin.sparkline :points="$total['series']" :label="__($t.'.trend', ['days' => $days])" class="h-10 w-full" /></dd>
                </div>
            </dl>

            {{-- Range switch --}}
            <div class="mt-5 flex items-center gap-2 text-sm" role="group" aria-label="{{ __($t.'.range') }}">
                <span class="text-slate-500">{{ __($t.'.range') }}:</span>
                @foreach ([30, 90] as $option)
                    <button type="button" wire:click="setRange({{ $option }})" wire:key="range-{{ $option }}" @class(['h-8 rounded-lg border px-3 text-xs font-semibold', 'border-brand-600 bg-brand-50 text-brand-700' => $days === $option, 'border-slate-200 text-slate-600 hover:bg-slate-50' => $days !== $option]) data-disk-range="{{ $option }}">{{ __($t.'.days', ['count' => $option]) }}</button>
                @endforeach
            </div>

            {{-- Top-level table --}}
            <div class="mt-3 overflow-x-auto rounded-xl border border-slate-200">
                <table class="w-full min-w-[40rem] text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-4 py-2.5 font-semibold">{{ __($t.'.col.folder') }}</th>
                            <th scope="col" class="px-4 py-2.5 text-right font-semibold">{{ __($t.'.col.size') }}</th>
                            <th scope="col" class="px-4 py-2.5 text-right font-semibold">{{ __($t.'.col.files') }}</th>
                            <th scope="col" class="px-4 py-2.5 text-right font-semibold">{{ __($t.'.days', ['count' => 7]) }}</th>
                            <th scope="col" class="px-4 py-2.5 text-right font-semibold">{{ __($t.'.days', ['count' => 30]) }}</th>
                            <th scope="col" class="px-4 py-2.5 font-semibold">{{ __($t.'.col.trend') }}</th>
                            <th scope="col" class="px-4 py-2.5 font-semibold">{{ __($t.'.col.status') }}</th>
                        </tr>
                    </thead>
                    <tbody x-data="{ open: false }" class="divide-y divide-slate-100">
                        @foreach ($buckets as $bucket)
                            @php $row = $history[$bucket->value] ?? null; @endphp
                            <tr wire:key="bucket-{{ $bucket->value }}" data-disk-row="{{ $bucket->value }}">
                                <th scope="row" class="px-4 py-2.5 font-medium text-slate-900">
                                    @if ($bucket === DiskTarget::Storage)
                                        <button type="button" class="inline-flex items-center gap-1.5 text-left" x-on:click="open = ! open" x-bind:aria-expanded="open" data-disk-expand>
                                            <x-admin.icon name="chevron-right" size="size-4" class="transition" x-bind:class="open && 'rotate-90'" />{{ $bucket->label() }}
                                        </button>
                                    @else
                                        {{ $bucket->label() }}
                                    @endif
                                </th>
                                @if ($row === null)
                                    <td colspan="6" class="px-4 py-2.5 text-slate-400">{{ __('admin.common.empty_value') }}</td>
                                @else
                                    <td class="whitespace-nowrap px-4 py-2.5 text-right font-semibold">@if ($row['status'] === DiskMeasureStatus::Partial)<span class="text-xs font-medium text-amber-700">{{ __($t.'.at_least') }}</span> @endif{{ $size($row['bytes']) }}</td>
                                    <td class="whitespace-nowrap px-4 py-2.5 text-right text-slate-600">{{ number_format($row['files'], 0, '.', ' ') }}</td>
                                    <td @class(['whitespace-nowrap px-4 py-2.5 text-right', $deltaClass($row['changes'][7])]) @if ($row['bases'][7]) title="{{ __($t.'.vs', ['date' => \Illuminate\Support\Carbon::parse($row['bases'][7])->format('d.m')]) }}" @endif>{{ $delta($row['changes'][7]) }}</td>
                                    <td @class(['whitespace-nowrap px-4 py-2.5 text-right', $deltaClass($row['changes'][30])]) @if ($row['bases'][30]) title="{{ __($t.'.vs', ['date' => \Illuminate\Support\Carbon::parse($row['bases'][30])->format('d.m')]) }}" @endif>{{ $delta($row['changes'][30]) }}</td>
                                    <td class="px-4 py-2.5"><x-admin.sparkline :points="$row['series']" :label="$bucket->label()" /></td>
                                    <td class="px-4 py-2.5"><x-admin.badge :color="$row['status']->color()">{{ $row['status']->label() }}</x-admin.badge></td>
                                @endif
                            </tr>

                            @if ($bucket === DiskTarget::Storage)
                                @foreach (DiskTarget::storageDetails() as $detail)
                                    @php $sub = $history[$detail->value] ?? null; @endphp
                                    <tr x-show="open" style="display: none" class="bg-slate-50/60" wire:key="detail-{{ $detail->value }}" data-disk-detail="{{ $detail->value }}">
                                        <th scope="row" class="py-2 pl-10 pr-4 text-sm font-normal text-slate-700">{{ $detail->label() }}</th>
                                        @if ($sub === null)
                                            <td colspan="6" class="px-4 py-2 text-slate-400">{{ __('admin.common.empty_value') }}</td>
                                        @else
                                            <td class="whitespace-nowrap px-4 py-2 text-right">@if ($sub['status'] === DiskMeasureStatus::Partial)<span class="text-xs text-amber-700">{{ __($t.'.at_least') }}</span> @endif{{ $size($sub['bytes']) }}</td>
                                            <td class="whitespace-nowrap px-4 py-2 text-right text-slate-600">{{ number_format($sub['files'], 0, '.', ' ') }}</td>
                                            <td @class(['whitespace-nowrap px-4 py-2 text-right', $deltaClass($sub['changes'][7])])>{{ $delta($sub['changes'][7]) }}</td>
                                            <td @class(['whitespace-nowrap px-4 py-2 text-right', $deltaClass($sub['changes'][30])])>{{ $delta($sub['changes'][30]) }}</td>
                                            <td class="px-4 py-2"><x-admin.sparkline :points="$sub['series']" :label="$detail->label()" /></td>
                                            <td class="px-4 py-2"><x-admin.badge :color="$sub['status']->color()">{{ $sub['status']->label() }}</x-admin.badge></td>
                                        @endif
                                    </tr>
                                @endforeach
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Limits form --}}
        @if ($canManage)
            <form wire:submit="saveLimits" class="mt-6 border-t border-slate-100 pt-5" data-disk-limits-form>
                <h3 class="text-sm font-semibold">{{ __($t.'.form.title') }}</h3>
                <p class="mt-0.5 text-xs text-slate-500">{{ __($t.'.form.hint') }}</p>
                @unless ($passwordConfirmed)
                    <p class="mt-2 text-xs text-amber-800" data-disk-password-required>{{ __($t.'.form.password') }}</p>
                @endunless
                <div class="mt-3 grid gap-4 sm:grid-cols-3 sm:items-end">
                    <x-admin.input wire:model="form.warn" type="number" inputmode="numeric" min="1" max="9999" step="1" :label="__($t.'.form.warn')" />
                    <x-admin.input wire:model="form.critical" type="number" inputmode="numeric" min="2" max="10000" step="1" :label="__($t.'.form.critical')" />
                    <div><x-admin.button type="submit" loading="saveLimits">{{ __($t.'.form.save') }}</x-admin.button></div>
                </div>
            </form>
        @endif
    </x-admin.card>
</div>
