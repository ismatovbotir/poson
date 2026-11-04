@php
    $t = 'monitoring';
    $when = fn ($date) => $date ? $date->timezone('Asia/Tashkent')->format('d.m H:i:s') : __('admin.common.empty_value');
    $schedulerAge = $scheduler ? (int) $scheduler->diffInSeconds(now(), true) : null;
    $workerAge = $worker?->ticked_at ? (int) $worker->ticked_at->diffInSeconds(now(), true) : null;
    $queueOldest = $queue['oldest_age'];
@endphp

<div wire:poll.visible.30s data-monitoring-screen>
    <x-admin.page-header :title="__($t.'.title')" :subtitle="__($t.'.subtitle')">
        <x-slot:breadcrumbs>{{ __('menu.groups.system') }} <span class="text-slate-300">/</span> {{ __($t.'.title') }}</x-slot:breadcrumbs>
    </x-admin.page-header>

    @if ($canManage && ! $passwordConfirmed)
        <div class="mt-4 flex flex-col gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 sm:flex-row sm:items-center sm:justify-between" role="status" data-password-required>
            <p class="flex gap-2"><x-admin.icon name="lock" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.confirm_required', ['minutes' => intdiv(\App\Support\Admin\PasswordConfirmation::timeout(), 60)]) }}</p>
            <x-admin.button size="sm" icon="shield" wire:click="confirmPassword">{{ __($t.'.confirm_button') }}</x-admin.button>
        </div>
    @endif

    @if ($retryAfterTooLow)
        <p class="mt-4 flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="note" data-retry-after-warning>
            <x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.retry_after_warning', ['retry_after' => $retryAfter, 'timeout' => \App\Jobs\RunScheduledTask::TIMEOUT]) }}
        </p>
    @endif

    @foreach ($telegramProblems as $event => $since)
        <p wire:key="telegram-problem-{{ $event }}" class="mt-4 flex gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-danger" role="alert" data-telegram-problem="{{ $event }}">
            <x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />
            <span>{{ __('telegram.monitoring.events.'.$event) }} · {{ $when($since) }}. @can('telegram.bot')<a href="{{ route('admin.telegram.bot') }}" wire:navigate class="font-semibold underline">{{ __($t.'.telegram_problem_open') }}</a>@endcan</span>
        </p>
    @endforeach

    {{-- Cards --}}
    <dl class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-2xl border border-slate-200 bg-white p-4" data-card="cron">
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __($t.'.cards.cron') }}</dt>
            <dd class="mt-2">
                <x-admin.badge :color="$schedulerAge === null || $schedulerAge > 120 ? 'red' : 'brand'" dot>{{ $schedulerAge === null ? __($t.'.cards.never') : ($schedulerAge > 120 ? __($t.'.cards.down') : __($t.'.cards.ok')) }}</x-admin.badge>
                <p class="mt-2 text-sm text-slate-600">{{ __($t.'.cards.last_tick') }}: <span class="font-semibold text-slate-900" data-cron-tick>{{ $when($scheduler) }}</span></p>
            </dd>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4" data-card="worker">
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __($t.'.cards.worker') }}</dt>
            <dd class="mt-2">
                <x-admin.badge :color="$workerAge === null || $workerAge > 180 ? 'red' : 'brand'" dot>{{ $workerAge === null ? __($t.'.cards.never') : ($workerAge > 180 ? __($t.'.cards.down') : __($t.'.cards.ok')) }}</x-admin.badge>
                <p class="mt-2 text-sm text-slate-600">{{ __($t.'.cards.last_tick') }}: <span class="font-semibold text-slate-900">{{ $when($worker?->ticked_at) }}</span></p>
                @if (isset($worker?->meta['lag_ms']))
                    <p class="text-sm text-slate-600">{{ __($t.'.cards.lag') }}: <span class="font-semibold text-slate-900">{{ number_format((int) $worker->meta['lag_ms'], 0, '.', ' ') }} {{ __($t.'.units.ms') }}</span></p>
                @endif
            </dd>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4" data-card="queue">
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __($t.'.cards.queue') }}</dt>
            <dd class="mt-2 space-y-1 text-sm text-slate-600">
                <p>{{ __($t.'.cards.pending') }}: <span class="font-semibold text-slate-900" data-queue-pending>{{ $queue['pending'] }}</span></p>
                <p>{{ __($t.'.cards.oldest') }}: <span @class(['font-semibold', 'text-danger' => $queueOldest !== null && $queueOldest >= 600, 'text-slate-900' => $queueOldest === null || $queueOldest < 600])>{{ $queueOldest === null ? __('admin.common.empty_value') : intdiv($queueOldest, 60).' '.__($t.'.units.min') }}</span></p>
                <p>{{ __($t.'.cards.failed') }}: <span @class(['font-semibold', 'text-danger' => $queue['failed'] > 0, 'text-slate-900' => $queue['failed'] === 0]) data-queue-failed>{{ $queue['failed'] }}</span></p>
            </dd>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4" data-card="alerts">
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __($t.'.cards.alerts') }}</dt>
            <dd class="mt-2 space-y-1.5 text-sm text-slate-600">
                <p class="flex items-center gap-2">Telegram <x-admin.badge :color="$telegramConfigured ? 'brand' : 'amber'">{{ $telegramConfigured ? __($t.'.cards.configured') : __($t.'.cards.not_configured') }}</x-admin.badge></p>
                <p class="flex items-center gap-2">{{ __($t.'.cards.ping') }} <x-admin.badge :color="$pingConfigured ? 'brand' : 'muted'">{{ $pingConfigured ? __($t.'.cards.configured') : __($t.'.cards.not_configured') }}</x-admin.badge></p>
                @unless ($telegramConfigured)
                    <p class="text-xs"><a href="{{ route('admin.telegram.settings') }}" wire:navigate class="font-semibold text-brand-700 hover:underline">{{ __($t.'.cards.set_up_telegram') }}</a></p>
                @endunless
                @unless ($pingConfigured)
                    <p class="text-xs"><a href="{{ route('admin.settings.integrations') }}" wire:navigate class="font-semibold text-brand-700 hover:underline">{{ __($t.'.cards.set_up') }}</a></p>
                @endunless
            </dd>
        </div>
    </dl>

    {{-- Scheduled tasks --}}
    <x-admin.card class="mt-5" :title="__($t.'.tasks.title')" :description="__($t.'.tasks.hint')" :padding="false">
        @if ($snapshotStale)
            <p class="mx-4 mt-4 flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 sm:mx-6" role="note" data-snapshot-stale>
                <x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ $snapshotAt ? __($t.'.tasks.stale', ['time' => $when($snapshotAt)]) : __($t.'.tasks.no_snapshot') }}
            </p>
        @endif

        @if ($tasks === [])
            <div class="p-4 sm:p-6"><x-admin.empty-state icon="clock" :title="__($t.'.tasks.empty')" /></div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">{{ __($t.'.tasks.title') }}</caption>
                    <thead class="border-b border-slate-200 bg-slate-50/70 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-4 py-3">{{ __($t.'.tasks.task') }}</th>
                            <th scope="col" class="px-4 py-3">{{ __($t.'.tasks.last_run') }}</th>
                            <th scope="col" class="px-4 py-3">{{ __($t.'.tasks.status') }}</th>
                            <th scope="col" class="px-4 py-3">{{ __($t.'.tasks.duration') }}</th>
                            <th scope="col" class="px-4 py-3">{{ __($t.'.tasks.next_run') }}</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">{{ __($t.'.tasks.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($tasks as $key => $info)
                            @php
                                $run = $latest->get($key);
                            @endphp
                            <tr wire:key="task-{{ md5($key) }}" data-task="{{ $key }}">
                                <td class="max-w-xs px-4 py-3 align-top">
                                    <p class="break-all font-mono text-xs font-semibold text-slate-900">{{ $key }}</p>
                                    <p class="mt-0.5 text-xs text-slate-500">{{ $info['expression'] ?? '' }} · {{ $info['timezone'] ?? '' }}</p>
                                    @if ($run?->error)
                                        <p class="mt-1 break-words text-xs text-danger" data-task-error>{{ $run->error }}</p>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 align-top text-slate-600">
                                    {{ $when($run?->started_at) }}
                                    @if ($run?->trigger === \App\Enums\TaskRunTrigger::Manual)
                                        <span class="text-xs text-slate-400">· {{ $run->trigger->label() }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 align-top">
                                    @if ($run)
                                        <x-admin.badge :color="$run->status->color()">{{ $run->status->label() }}</x-admin.badge>
                                    @else
                                        <span class="text-slate-400">{{ __('admin.common.empty_value') }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 align-top text-slate-600">{{ $run?->duration_ms !== null ? number_format($run->duration_ms, 0, '.', ' ').' '.__($t.'.units.ms') : __('admin.common.empty_value') }}</td>
                                <td class="whitespace-nowrap px-4 py-3 align-top text-slate-600">{{ isset($info['next_run']) ? \Illuminate\Support\Carbon::parse($info['next_run'])->timezone('Asia/Tashkent')->format('d.m H:i') : __('admin.common.empty_value') }}</td>
                                <td class="px-4 py-3 align-top">
                                    <div class="flex justify-end gap-2">
                                        <x-admin.button size="sm" variant="ghost" wire:click="toggleRuns(@js($key))" wire:loading.attr="disabled" wire:target="toggleRuns" data-toggle-runs>{{ $expanded === $key ? __($t.'.tasks.hide_runs') : __($t.'.tasks.show_runs') }}</x-admin.button>
                                        @if ($canManage && ($info['runnable'] ?? false))
                                            <x-admin.button
                                                size="sm"
                                                variant="secondary"
                                                icon="restore"
                                                :disabled="! $passwordConfirmed"
                                                x-on:click="$dispatch('confirm', {
                                                    title: {{ \Illuminate\Support\Js::from(__($t.'.confirm.run_title')) }},
                                                    body: {{ \Illuminate\Support\Js::from(__($t.'.confirm.run_body', ['task' => $key])) }},
                                                    label: {{ \Illuminate\Support\Js::from(__($t.'.tasks.run_now')) }},
                                                    component: $wire.$id, method: 'runNow', params: [{{ \Illuminate\Support\Js::from($key) }}],
                                                })"
                                                loading="runNow"
                                                data-run-now
                                            >{{ __($t.'.tasks.run_now') }}</x-admin.button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @if ($expanded === $key)
                                <tr wire:key="runs-{{ md5($key) }}" class="bg-slate-50/60" data-runs="{{ $key }}">
                                    <td colspan="6" class="px-4 py-3">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __($t.'.tasks.last_runs', ['count' => \App\Livewire\Admin\System\Monitoring::RUNS_SHOWN]) }}</p>
                                        <ul class="mt-2 divide-y divide-slate-100 text-sm">
                                            @foreach ($runs as $past)
                                                <li class="flex flex-wrap items-center gap-x-4 gap-y-1 py-1.5" wire:key="run-{{ $past->id }}">
                                                    <span class="w-28 text-slate-600">{{ $when($past->started_at) }}</span>
                                                    <x-admin.badge :color="$past->status->color()">{{ $past->status->label() }}</x-admin.badge>
                                                    <span class="text-slate-500">{{ $past->duration_ms !== null ? number_format($past->duration_ms, 0, '.', ' ').' '.__($t.'.units.ms') : '' }}</span>
                                                    <span class="text-xs text-slate-400">{{ $past->trigger->label() }}</span>
                                                    @if ($past->error)<span class="break-words text-xs text-danger">{{ $past->error }}</span>@endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-admin.card>

    {{-- Failed jobs --}}
    <x-admin.card class="mt-5" :title="__($t.'.failed.title')" :description="__($t.'.failed.hint')" :padding="false">
        @if ($canManage && $failed->total() > 0)
            <x-slot:actions>
                <x-admin.button
                    size="sm"
                    variant="secondary"
                    icon="restore"
                    :disabled="$selected === [] || ! $passwordConfirmed"
                    x-on:click="$dispatch('confirm', {
                        title: {{ \Illuminate\Support\Js::from(__($t.'.confirm.retry_title')) }},
                        body: {{ \Illuminate\Support\Js::from(__($t.'.confirm.retry_body')) }},
                        label: {{ \Illuminate\Support\Js::from(__($t.'.failed.retry')) }},
                        component: $wire.$id, method: 'retrySelected', params: [],
                    })"
                    loading="retrySelected"
                    data-retry-selected
                >{{ __($t.'.failed.retry_selected') }}</x-admin.button>
                <x-admin.button
                    size="sm"
                    variant="danger"
                    icon="trash"
                    :disabled="$selected === [] || ! $passwordConfirmed"
                    x-on:click="$dispatch('confirm', {
                        title: {{ \Illuminate\Support\Js::from(__($t.'.confirm.forget_title')) }},
                        body: {{ \Illuminate\Support\Js::from(__($t.'.confirm.forget_body')) }},
                        label: {{ \Illuminate\Support\Js::from(__($t.'.failed.forget')) }},
                        component: $wire.$id, method: 'forgetSelected', params: [],
                    })"
                    loading="forgetSelected"
                    data-forget-selected
                >{{ __($t.'.failed.forget_selected') }}</x-admin.button>
            </x-slot:actions>
        @endif

        @if ($failed->isEmpty())
            <div class="p-4 sm:p-6"><x-admin.empty-state icon="check" :title="__($t.'.failed.empty')" /></div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">{{ __($t.'.failed.title') }}</caption>
                    <thead class="border-b border-slate-200 bg-slate-50/70 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="w-10 px-4 py-3"></th>
                            <th scope="col" class="px-4 py-3">{{ __($t.'.failed.job') }}</th>
                            <th scope="col" class="px-4 py-3">{{ __($t.'.failed.queue') }}</th>
                            <th scope="col" class="px-4 py-3">{{ __($t.'.failed.failed_at') }}</th>
                            <th scope="col" class="px-4 py-3">{{ __($t.'.failed.error') }}</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">{{ __($t.'.tasks.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($failed as $job)
                            @php
                                $class = \App\Support\Monitoring\FailedJobs::normalizeClass($job->display_name);
                                $retryable = \App\Support\Monitoring\FailedJobs::isRetryable($class);
                                $labelKey = \App\Support\Monitoring\FailedJobs::labelKey($class);
                                $failedAt = \Illuminate\Support\Carbon::parse($job->failed_at);
                            @endphp
                            <tr wire:key="failed-{{ $job->uuid }}" data-failed-job="{{ $job->uuid }}">
                                <td class="px-4 py-3 align-top">
                                    @if ($canManage)
                                        <input type="checkbox" wire:model="selected" value="{{ $job->uuid }}" class="size-4 rounded border-slate-300 text-brand-600" aria-label="{{ __($t.'.failed.select') }}">
                                    @endif
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <p class="break-all font-mono text-xs font-semibold text-slate-900">{{ class_basename($class) !== '' ? class_basename($class) : __('admin.common.empty_value') }}</p>
                                    @if ($labelKey)
                                        <p class="mt-0.5 text-xs text-slate-500">{{ __($t.'.job_labels.'.$labelKey) }}</p>
                                    @endif
                                    @if ($failedAt->lt(now()->subHours(\App\Support\Monitoring\FailedJobs::STALE_HOURS)))
                                        <p class="mt-1 text-xs font-semibold text-amber-700" data-stale-job>{{ __($t.'.failed.stale_warning', ['hours' => \App\Support\Monitoring\FailedJobs::STALE_HOURS]) }}</p>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 align-top text-slate-600">{{ $job->queue }}</td>
                                <td class="whitespace-nowrap px-4 py-3 align-top text-slate-600">{{ $failedAt->timezone('Asia/Tashkent')->format('d.m.Y H:i') }}</td>
                                <td class="max-w-sm break-words px-4 py-3 align-top text-xs text-slate-600">{{ \App\Support\Monitoring\FailedJobs::oneLine($job->exception_excerpt) }}</td>
                                <td class="px-4 py-3 align-top">
                                    <div class="flex justify-end gap-2">
                                        <x-admin.button size="sm" variant="ghost" wire:click="showFailed(@js($job->uuid))" wire:loading.attr="disabled" wire:target="showFailed" data-failed-details>{{ __($t.'.failed.details') }}</x-admin.button>
                                        @if ($canManage)
                                            @if ($retryable)
                                                <x-admin.button size="sm" variant="secondary" wire:click="retryOne(@js($job->uuid))" :disabled="! $passwordConfirmed" loading="retryOne" data-retry-one>{{ __($t.'.failed.retry') }}</x-admin.button>
                                            @endif
                                            <x-admin.button
                                                size="sm"
                                                variant="ghost"
                                                :disabled="! $passwordConfirmed"
                                                x-on:click="$dispatch('confirm', {
                                                    title: {{ \Illuminate\Support\Js::from(__($t.'.confirm.forget_title')) }},
                                                    body: {{ \Illuminate\Support\Js::from(__($t.'.confirm.forget_body')) }},
                                                    label: {{ \Illuminate\Support\Js::from(__($t.'.failed.forget')) }},
                                                    component: $wire.$id, method: 'forgetOne', params: [{{ \Illuminate\Support\Js::from($job->uuid) }}],
                                                })"
                                                loading="forgetOne"
                                                data-forget-one
                                            >{{ __($t.'.failed.forget') }}</x-admin.button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-100 p-4">{{ $failed->links() }}</div>
        @endif
    </x-admin.card>

    {{-- Failed job details --}}
    @if ($detail)
        <div class="fixed inset-0 z-[60] flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" aria-labelledby="failed-job-title" x-data x-on:keydown.escape.window="$wire.closeFailed()" data-failed-detail>
            <div class="absolute inset-0 bg-slate-900/50" wire:click="closeFailed"></div>
            <div class="relative w-full rounded-t-2xl bg-white p-5 pb-[calc(1.25rem+env(safe-area-inset-bottom))] shadow-2xl sm:max-w-lg sm:rounded-2xl sm:pb-5">
                <h2 id="failed-job-title" class="text-base font-semibold">{{ __($t.'.failed.detail_title') }}</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __($t.'.failed.job') }}</dt><dd class="break-all font-mono text-xs">{{ \App\Support\Monitoring\FailedJobs::normalizeClass($detail['row']->display_name) }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">uuid</dt><dd class="break-all font-mono text-xs">{{ $detail['row']->uuid }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __($t.'.failed.exception') }}</dt><dd class="break-all font-mono text-xs">{{ $detail['info']['class'] }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __($t.'.failed.message') }}</dt><dd class="whitespace-pre-line break-words text-xs">{{ $detail['info']['message'] }}</dd></div>
                    @if ($detail['info']['frame'])
                        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __($t.'.failed.frame') }}</dt><dd class="break-all font-mono text-xs">{{ $detail['info']['frame'] }}</dd></div>
                    @endif
                </dl>
                <div class="mt-6 flex justify-end">
                    <button type="button" wire:click="closeFailed" class="h-11 rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50 sm:h-10">{{ __($t.'.close') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
