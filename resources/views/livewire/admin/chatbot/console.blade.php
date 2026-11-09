@php
    $t = 'chatbot_admin.console';
    $result = $this->result;
@endphp

<div data-chatbot-console>
    <x-admin.page-header :title="__($t.'.title')" :subtitle="__($t.'.subtitle')">
        <x-slot:breadcrumbs>{{ __('menu.groups.chatbot') }} <span class="text-slate-300">/</span> {{ __($t.'.title') }}</x-slot:breadcrumbs>
    </x-admin.page-header>

    <p class="mt-4 flex items-start gap-2 rounded-lg bg-brand-50 px-3 py-2.5 text-sm text-brand-800" role="note">
        <x-admin.icon name="info" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.no_writes') }}
    </p>

    <form wire:submit="run" novalidate class="mt-5 grid gap-4 lg:grid-cols-12">
        <x-admin.input wire:model="phrase" name="phrase" :label="__($t.'.phrase')" maxlength="{{ \App\Livewire\Admin\Chatbot\Console::MAX_CHARS }}" autocomplete="off" required class="lg:col-span-6" />
        <x-admin.select wire:model="locale" name="locale" :label="__($t.'.locale')" :options="['uz' => 'UZ', 'ru' => 'RU', 'en' => 'EN']" class="lg:col-span-2" />
        <x-admin.select wire:model="channel" name="channel" :label="__($t.'.channel')" :options="collect(\App\Enums\ChatChannel::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" class="lg:col-span-2" />
        <div class="flex items-end lg:col-span-2">
            <x-admin.button type="submit" icon="search" loading="run" class="w-full">{{ __($t.'.run') }}</x-admin.button>
        </div>
    </form>

    @if ($result !== null)
        <div class="mt-6 grid gap-4 lg:grid-cols-2" data-console-result>
            <x-admin.card :title="__($t.'.result')">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">{{ __($t.'.normalized') }}</dt>
                        <dd><code class="rounded bg-slate-100 px-1.5 py-0.5" data-console-normalized>{{ $result['normalized'] !== '' ? $result['normalized'] : '—' }}</code></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">{{ __($t.'.matched_rule') }}</dt>
                        <dd class="text-right font-semibold" data-console-rule>
                            @if ($result['rule'])
                                <a href="{{ route('admin.chatbot.rules.edit', $result['rule']['id']) }}" wire:navigate class="hover:text-brand-700">{{ $result['rule']['name'] }}</a>
                                <span class="block text-xs font-normal text-slate-500">{{ $result['rule']['action_label'] }}</span>
                            @else
                                <span class="text-slate-500">{{ __($t.'.no_match') }}</span>
                            @endif
                        </dd>
                    </div>
                    @if ($result['score'] !== null)
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-500">{{ __($t.'.score') }}</dt>
                            <dd class="font-semibold tabular-nums" data-console-score>{{ $result['score'] }} <span class="font-normal text-slate-500">/ {{ __($t.'.needed', ['min' => $result['min_score']]) }}</span></dd>
                        </div>
                    @endif
                    @if ($result['would_run'])
                        <p class="rounded-lg bg-amber-50 px-3 py-2 text-amber-800" data-console-would-run>{{ __($t.'.would_run', ['action' => $result['would_run']]) }}</p>
                    @endif
                    @if ($result['fallback'])
                        <p class="rounded-lg bg-slate-50 px-3 py-2 text-slate-600">{{ __($t.'.fallback_note') }}</p>
                    @endif
                </dl>

                @if ($result['keywords'] !== [])
                    <h3 class="mt-5 text-sm font-semibold">{{ __($t.'.breakdown') }}</h3>
                    <ul class="mt-2 divide-y divide-slate-100 text-sm" data-console-keywords>
                        @foreach ($result['keywords'] as $hit)
                            <li class="flex items-center justify-between gap-3 py-2">
                                <span><code class="rounded bg-slate-100 px-1.5 py-0.5">{{ $hit['keyword'] }}</code> <span class="text-xs text-slate-500">{{ \App\Enums\KeywordMatch::tryFrom($hit['type'])?->label() ?? $hit['type'] }}</span></span>
                                <span class="font-semibold tabular-nums">+{{ $hit['weight'] }}</span>
                            </li>
                        @endforeach
                        @if ($result['priority'])
                            <li class="flex items-center justify-between gap-3 py-2"><span class="text-slate-600">{{ __($t.'.priority') }}</span><span class="font-semibold tabular-nums">{{ $result['priority'] > 0 ? '+' : '' }}{{ $result['priority'] }}</span></li>
                        @endif
                    </ul>
                @endif
            </x-admin.card>

            <x-admin.card :title="__($t.'.reply')">
                @if ($result['reply'])
                    {{-- Plain text, escaped, line breaks kept (CB-5). --}}
                    <div class="whitespace-pre-line rounded-2xl rounded-bl-sm bg-slate-100 px-4 py-3 text-sm" data-console-reply>{{ $result['reply']['text'] }}</div>
                    @if ($result['reply']['buttons'] !== [])
                        <ul class="mt-3 flex flex-wrap gap-2" data-console-buttons>
                            @foreach ($result['reply']['buttons'] as $button)
                                <li class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700">{{ $button['label'] }}</li>
                            @endforeach
                        </ul>
                    @endif
                @else
                    <p class="text-sm text-slate-500">{{ __($t.'.no_reply') }}</p>
                @endif
            </x-admin.card>
        </div>
    @endif
</div>
