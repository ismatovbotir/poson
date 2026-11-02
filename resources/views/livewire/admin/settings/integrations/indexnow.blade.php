@php
    /** @var array{active: bool, production: bool, public: bool, open: bool, key_url: string|null, log: list<array{at: string, urls: int, status: string, code: string}>, pending: int, backlog: int} $indexnow */
    $t = 'admin.settings.integrations.indexnow';
@endphp

{{-- IndexNow status (plan 18): masked key URL, gate warnings, counts and the last results. Never the key, never URLs. --}}
<div class="mt-5 space-y-3 border-t border-slate-100 pt-5" data-indexnow>
    @if (! $indexnow['production'])
        <p class="flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="note" data-indexnow-not-production>
            <x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.not_production') }}
        </p>
    @elseif (! $indexnow['public'])
        <p class="flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="note" data-indexnow-not-public>
            <x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.not_public', ['url' => config('app.url')]) }}
        </p>
    @elseif (! $indexnow['open'])
        <p class="flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="note" data-indexnow-closed>
            <x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.indexing_closed') }}
        </p>
    @endif

    <p class="flex flex-wrap items-center gap-2 text-sm" data-indexnow-state>
        <x-admin.badge :color="$indexnow['active'] ? 'brand' : 'muted'" :dot="$indexnow['active']">{{ $indexnow['active'] ? __($t.'.state_active') : __($t.'.state_inactive') }}</x-admin.badge>
        @if ($indexnow['key_url'])
            <span class="text-xs text-slate-500">{{ __($t.'.key_url') }}</span>
            <span class="font-mono text-xs text-slate-700" data-indexnow-key-url>{{ $indexnow['key_url'] }}</span>
        @endif
    </p>

    <p class="text-xs text-slate-500" data-indexnow-counts>{{ __($t.'.counts', ['pending' => $indexnow['pending'], 'backlog' => $indexnow['backlog']]) }}</p>

    <div data-indexnow-log>
        <p class="text-sm font-semibold">{{ __($t.'.log_title') }}</p>
        @if ($indexnow['log'] === [])
            <p class="mt-1 text-xs text-slate-500">{{ __($t.'.log_empty') }}</p>
        @else
            <ul class="mt-2 divide-y divide-slate-100 rounded-lg border border-slate-200 text-xs">
                @foreach ($indexnow['log'] as $i => $entry)
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2" wire:key="indexnow-log-{{ $i }}">
                        <span class="text-slate-500">{{ \Illuminate\Support\Carbon::parse($entry['at'])->timezone('Asia/Tashkent')->format('d.m.Y H:i') }}</span>
                        <span>{{ __($t.'.log_urls', ['count' => $entry['urls']]) }}</span>
                        <x-admin.badge :color="match ($entry['status']) { 'ok' => 'brand', 'failed' => 'red', 'retry' => 'amber', default => 'muted' }">{{ __($t.'.status_'.$entry['status']) }}</x-admin.badge>
                        <span class="font-mono text-slate-500">{{ $entry['code'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
