@php($t = 'office.dry')

{{-- Virtual office → Education → Test console (plan 46 §2.3). Admin only. Nothing is saved; the transcript is never shown. All values escaped. --}}
<div data-office-dry-run>
    <x-admin.page-header :title="__($t.'.title')" :subtitle="__($t.'.subtitle')">
        <x-slot:breadcrumbs>{{ __('menu.groups.office') }} <span class="text-slate-300">/</span> <a href="{{ route('admin.office.education', $role) }}" wire:navigate class="hover:underline">{{ __('office.education.title') }}</a> <span class="text-slate-300">/</span> {{ __($t.'.title') }}</x-slot:breadcrumbs>
    </x-admin.page-header>

    <nav class="mt-4 flex flex-wrap rounded-lg border border-slate-200 bg-white p-1" aria-label="{{ __('office.education.roles') }}">
        @foreach ($roles as $case)
            <a href="{{ route('admin.office.education.test', $case->value) }}" wire:navigate @class(['h-8 rounded-md px-3 text-sm font-semibold leading-8', 'bg-slate-900 text-white' => $case->value === $role, 'text-slate-600 hover:text-slate-900' => $case->value !== $role])>{{ $case->label() }}</a>
        @endforeach
    </nav>

    @unless ($aiOn)
        <p class="mt-4 rounded-lg bg-amber-50 px-3 py-2.5 text-sm text-amber-800" role="note" data-ai-off>{{ __($t.'.errors.ai_off') }}</p>
    @endunless

    <p class="mt-4 text-sm text-slate-600" data-usage>{{ __($t.'.usage', ['used' => $usedRequests, 'max' => $caps['requests']]) }}</p>
    @error('dry')<p class="mt-3 rounded-lg bg-red-50 px-3 py-2.5 text-sm text-danger" role="alert" data-dry-error>{{ $message }}</p>@enderror

    <x-admin.card class="mt-4" :title="__($t.'.setup')" :description="__($t.'.setup_hint')">
        <form wire:submit="start" class="grid gap-3 sm:grid-cols-3" novalidate>
            <div class="sm:col-span-1">
                <x-admin.select wire:model="sourceType" :label="__($t.'.source_type')" :options="['chat' => __($t.'.chat'), 'group' => __($t.'.group')]" />
            </div>
            <div class="sm:col-span-1">
                <x-admin.select wire:model="sourceId" :label="__($t.'.source')" :placeholder="__('office.feedback.choose')" :options="$sourceType === 'group' ? $groups->mapWithKeys(fn ($g) => [$g->id => '#'.$g->id.' '.($g->title ?? '')])->all() : $chats->mapWithKeys(fn ($c) => [$c->id => '#'.$c->id.' '.($c->first_name ?? '')])->all()" />
            </div>
            <div class="sm:col-span-1">
                <x-admin.select wire:model="versionB" :label="__($t.'.version_b')" :placeholder="__('office.feedback.choose')" :options="$versions->mapWithKeys(fn ($v) => [$v->id => 'v'.$v->version.($v->id === $currentId ? ' ('.__($t.'.published').')' : '').($v->change_note ? ' · '.$v->change_note : '')])->all()" />
            </div>
            <div class="sm:col-span-3 flex flex-wrap items-center gap-3">
                <x-admin.button type="submit" icon="check" loading="start" data-action="start">{{ __($t.'.start') }}</x-admin.button>
                <span class="text-xs text-slate-500">{{ __($t.'.privacy') }}</span>
            </div>
        </form>
    </x-admin.card>

    @if ($run)
        <x-admin.card class="mt-4" :title="__($t.'.results')" :description="$isRouter ? __($t.'.steps_router') : __($t.'.steps_agent')" data-dry-results>
            <div class="flex flex-wrap gap-2">
                @for ($step = 2; $step <= $lastStep; $step++)
                    @if (! isset($run['steps'][$step]) && ($run['steps'][$step - 1]['outcome'] ?? null) === 'ok')
                        <x-admin.button variant="secondary" size="sm" wire:click="runStep({{ $step }})" loading="runStep" data-action="step-{{ $step }}">{{ __($t.'.step_'.($isRouter ? 'router_b' : ($step === 2 ? 'a' : 'b'))) }}</x-admin.button>
                    @endif
                @endfor
            </div>
            @php($columns = [1 => 'lg:grid-cols-1', 2 => 'lg:grid-cols-2', 3 => 'lg:grid-cols-3'][min(3, max(1, count($run['steps'])))])
            <div class="mt-4 grid gap-4 {{ $columns }}">
                @foreach ($run['steps'] as $number => $result)
                    <div wire:key="step-{{ $number }}" class="rounded-lg border border-slate-200 p-3 text-sm" data-step="{{ $number }}">
                        <p class="font-semibold">{{ __($t.'.col_'.($result['label'] ?? 'step'), ['n' => $number]) }}@isset($result['version']) · v{{ $result['version'] }}@endisset</p>
                        @if (($result['outcome'] ?? 'ok') !== 'ok')
                            <p class="mt-1 text-amber-700" data-outcome="{{ $result['outcome'] }}">{{ __($t.'.outcomes.'.$result['outcome']) }}</p>
                        @else
                            <dl class="mt-2 space-y-1.5">
                                <div><dt class="text-xs text-slate-500">{{ __($t.'.route') }}</dt><dd>{{ \App\Enums\AgentRole::tryFrom((string) ($result['role'] ?? ''))?->label() }}@isset($result['confidence']) · {{ __('office.show.confidence', ['value' => $result['confidence']]) }}@endisset</dd></div>
                                @if (! empty($result['topic']))<div><dt class="text-xs text-slate-500">{{ __($t.'.topic') }}</dt><dd>{{ $result['topic'] }}</dd></div>@endif
                                @if (! empty($result['summary']))<div><dt class="text-xs text-slate-500">{{ __($t.'.summary') }}</dt><dd class="whitespace-pre-line break-words">{{ $result['summary'] }}</dd></div>@endif
                                @if (($result['kind'] ?? '') === 'agent')
                                    @if (! empty($result['next_step']))<div><dt class="text-xs text-slate-500">{{ __($t.'.next_step') }}</dt><dd class="whitespace-pre-line break-words">{{ $result['next_step'] }}</dd></div>@endif
                                    <div>
                                        <dt class="text-xs text-slate-500">{{ __($t.'.draft') }}</dt>
                                        @if (! empty($result['draft']))
                                            <dd class="whitespace-pre-line break-words" data-dry-draft>{{ $result['draft'] }}@if (! empty($result['hold'])) <span class="text-amber-700">({{ \App\Enums\DraftHold::from($result['hold'])->label() }})</span>@endif</dd>
                                        @elseif (! empty($result['hold']))
                                            <dd class="text-amber-700" data-dry-hold>{{ \App\Enums\DraftHold::from($result['hold'])->label() }}</dd>
                                        @elseif (! empty($result['blocked']))
                                            <dd class="text-amber-700" data-dry-hold>{{ __('office.show.draft_blocked') }}</dd>
                                        @else
                                            <dd class="text-slate-400">{{ __('office.show.no_draft') }}</dd>
                                        @endif
                                    </div>
                                    <div><dt class="text-xs text-slate-500">{{ __($t.'.escalation') }}</dt><dd>@if ($result['needs_human'] ?? false)<x-admin.badge color="red">{{ __('office.show.needs_human') }}</x-admin.badge> @endif{{ $result['escalation'] ?? __('admin.common.empty_value') }}</dd></div>
                                @endif
                                <div class="text-xs text-slate-500">{{ __($t.'.tokens', ['n' => $result['tokens'] ?? 0]) }}</div>
                            </dl>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-admin.card>
    @endif
</div>
