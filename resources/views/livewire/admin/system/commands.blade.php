{{--
    Command names, descriptions, help, usage, argument and option texts are
    English only on purpose (deliberate i18n exception, decision 2026-11-02):
    they come from the code. Only the page chrome is translated. Everything is
    printed with {{ }} (escaped) inside whitespace-pre-wrap; no raw HTML, no markdown.
--}}
@php
    $t = 'commands';
@endphp

<div data-commands-screen>
    <x-admin.page-header :title="__($t.'.title')" :subtitle="__($t.'.subtitle')">
        <x-slot:breadcrumbs>{{ __('menu.groups.system') }} <span class="text-slate-300">/</span> {{ __($t.'.title') }}</x-slot:breadcrumbs>
    </x-admin.page-header>

    <div class="mt-5 flex flex-wrap items-center gap-3">
        <label class="relative min-w-0 flex-1 basis-full sm:basis-auto sm:max-w-md">
            <span class="sr-only">{{ __($t.'.search') }}</span>
            <x-admin.icon name="search" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input wire:model.live.debounce.300ms="search" type="search" maxlength="100" placeholder="{{ __($t.'.search_placeholder') }}" class="h-11 w-full rounded-lg border border-slate-200 bg-white pl-10 pr-3 text-base outline-none placeholder:text-slate-400 focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 sm:text-sm lg:h-10" data-commands-search>
        </label>
        <x-admin.toggle wire:model.live="showFramework" :label="__($t.'.show_framework')" :description="__($t.'.show_framework_hint')" />
    </div>

    <p class="mt-3 text-xs text-slate-500">{{ __($t.'.count', ['count' => count($commands)]) }} · {{ __($t.'.note') }}</p>

    @if ($commands === [])
        <x-admin.empty-state class="mt-4" icon="search" :title="__($t.'.empty_title')" :body="__($t.'.empty_body')" data-commands-empty />
    @else
        <ul class="mt-4 space-y-3">
            @foreach ($commands as $command)
                <li wire:key="command-{{ md5($command['name']) }}" x-data="{ open: false }" class="rounded-2xl border border-slate-200 bg-white" data-command="{{ $command['name'] }}">
                    <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" class="flex w-full items-start gap-3 rounded-2xl p-4 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                        <span class="min-w-0 flex-1">
                            <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span class="break-all font-mono text-sm font-semibold text-slate-900">{{ $command['name'] }}</span>
                                @unless ($command['is_app'])
                                    <x-admin.badge color="muted">{{ __($t.'.framework') }}</x-admin.badge>
                                @endunless
                                @if ($command['is_app'])
                                    @if ($command['schedule'] !== [])
                                        @foreach ($command['schedule'] as $slot)
                                            <x-admin.badge color="brand">{{ __($t.'.scheduled') }}: {{ $slot['expression'] }}@if ($slot['human']) ({{ $slot['human'] }})@endif</x-admin.badge>
                                        @endforeach
                                    @else
                                        <x-admin.badge color="muted">{{ __($t.'.manual') }}</x-admin.badge>
                                    @endif
                                @endif
                            </span>
                            <span class="mt-1 block whitespace-pre-wrap break-words text-sm text-slate-600">{{ $command['description'] }}</span>
                        </span>
                        <x-admin.icon name="chevron-down" size="size-5" class="mt-0.5 shrink-0 text-slate-400 transition" x-bind:class="open && 'rotate-180'" />
                        <span class="sr-only">{{ __($t.'.details') }}</span>
                    </button>

                    <div x-show="open" x-cloak class="space-y-4 border-t border-slate-100 p-4 text-sm" data-command-details>
                        @if ($command['help'] !== '')
                            <div>
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __($t.'.help') }}</h3>
                                <p class="mt-1 whitespace-pre-wrap break-words text-slate-700" data-command-help>{{ $command['help'] }}</p>
                            </div>
                        @endif

                        <div>
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __($t.'.usage') }}</h3>
                            <p class="mt-1 whitespace-pre-wrap break-all rounded-lg bg-slate-50 px-3 py-2 font-mono text-xs text-slate-800">php artisan {{ $command['name'] }}@if ($command['usage'] !== '') {{ ltrim(\Illuminate\Support\Str::after($command['usage'], $command['name'])) }}@endif</p>
                        </div>

                        @foreach (['arguments' => $command['arguments'], 'options' => $command['options']] as $kind => $params)
                            @if ($params !== [])
                                <div>
                                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __($t.'.'.$kind) }}</h3>
                                    <dl class="mt-1 divide-y divide-slate-100">
                                        @foreach ($params as $param)
                                            <div class="py-2" data-param="{{ $param['name'] }}">
                                                <dt class="break-all font-mono text-xs font-semibold text-slate-900">
                                                    {{ $kind === 'options' ? '--' : '' }}{{ $param['name'] }}@if ($param['shortcut']) <span class="font-normal text-slate-500">(-{{ $param['shortcut'] }})</span>@endif
                                                    @if ($param['required'])<span class="ml-1 font-sans font-normal text-slate-500">{{ __($t.'.required') }}</span>@endif
                                                    @if ($param['is_array'])<span class="ml-1 font-sans font-normal text-slate-500">{{ __($t.'.array') }}</span>@endif
                                                </dt>
                                                <dd class="mt-0.5 whitespace-pre-wrap break-words text-slate-600">{{ $param['description'] }}@if ($param['default'] !== null) <span class="text-slate-500">({{ __($t.'.default') }}: {{ $param['default'] }})</span>@endif</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
