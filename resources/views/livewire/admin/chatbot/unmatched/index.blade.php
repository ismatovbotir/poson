@php
    $t = 'chatbot_admin.unmatched';
    $canEdit = auth()->user()->can('chatbot.rules');
    $selectClass = 'h-11 w-full appearance-none rounded-lg border border-slate-200 bg-white pl-3 pr-9 text-sm font-semibold text-slate-700 outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 lg:h-10';
@endphp

<div data-chatbot-unmatched>
    <x-admin.page-header :title="__($t.'.title')" :subtitle="__($t.'.subtitle')">
        <x-slot:breadcrumbs>{{ __('menu.groups.chatbot') }} <span class="text-slate-300">/</span> {{ __($t.'.title') }}</x-slot:breadcrumbs>
    </x-admin.page-header>

    <div class="mt-5 flex flex-wrap items-center gap-2">
        <label class="relative flex-1 sm:flex-none">
            <span class="sr-only">{{ __('admin.catalog.status') }}</span>
            <select wire:model.live="show" class="{{ $selectClass }}">
                <option value="open">{{ __($t.'.show_open') }}</option>
                <option value="resolved">{{ __($t.'.show_resolved') }}</option>
            </select>
            <x-admin.icon name="chevron-down" size="size-4" class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400" />
        </label>
        <label class="relative flex-1 sm:flex-none">
            <span class="sr-only">{{ __($t.'.columns.channel') }}</span>
            <select wire:model.live="channel" class="{{ $selectClass }}">
                <option value="">{{ __($t.'.channel_all') }}</option>
                @foreach (\App\Enums\ChatChannel::cases() as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                @endforeach
            </select>
            <x-admin.icon name="chevron-down" size="size-4" class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400" />
        </label>
    </div>

    <p class="mt-3 flex items-start gap-2 rounded-lg bg-slate-50 px-3 py-2.5 text-sm text-slate-600" role="note">
        <x-admin.icon name="info" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.mask_note') }}
    </p>

    <div class="relative mt-4">
        <div wire:loading.delay class="absolute inset-0 z-10 rounded-2xl bg-white/50" aria-hidden="true"></div>

        @if ($items->isEmpty())
            <x-admin.empty-state icon="check" :title="__($t.'.empty_title')" :body="__($t.'.empty_body')" />
        @else
            <ul class="space-y-3">
                @foreach ($items as $row)
                    <li wire:key="unmatched-{{ $row->id }}" class="rounded-2xl border border-slate-200 bg-white p-4" data-unmatched-row="{{ $row->id }}">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                {{-- Plain text only: stored redacted, escaped here (CB-5). --}}
                                <p class="break-words text-base font-semibold text-slate-900" lang="{{ $row->locale }}">{{ $row->sample }}</p>
                                <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">
                                    <span>{{ __($t.'.normalized') }}: <code class="rounded bg-slate-100 px-1.5 py-0.5 text-slate-700">{{ $row->normalized }}</code></span>
                                    <span>{{ $row->channel->label() }} · {{ strtoupper($row->locale) }}</span>
                                    <span>{{ __($t.'.last_seen', ['when' => $row->last_seen_at->diffForHumans()]) }}</span>
                                </p>
                            </div>
                            <x-admin.badge color="amber" pill>{{ __($t.'.times', ['count' => $row->count]) }}</x-admin.badge>
                        </div>

                        @if ($row->resolved_at)
                            <p class="mt-3 text-xs text-slate-500">
                                {{ $row->resolvedRule ? __($t.'.resolved_by', ['rule' => $row->resolvedRule->name]) : __($t.'.resolved_ignored') }}
                                · {{ $row->resolved_at->diffForHumans() }}
                            </p>
                        @elseif ($canEdit)
                            <div class="mt-3 flex flex-wrap gap-2">
                                <x-admin.button type="button" size="sm" variant="secondary" wire:click="openTeach({{ $row->id }})" data-teach="{{ $row->id }}">{{ __($t.'.add_keyword') }}</x-admin.button>
                                <x-admin.button size="sm" variant="secondary" :href="route('admin.chatbot.rules.create', ['keyword' => $row->normalized, 'locale' => $row->locale, 'unmatched' => $row->id])" wire:navigate>{{ __($t.'.create_rule') }}</x-admin.button>
                                <x-admin.button type="button" size="sm" variant="ghost" wire:click="ignore({{ $row->id }})" data-ignore="{{ $row->id }}">{{ __($t.'.ignore') }}</x-admin.button>
                            </div>

                            @if ($teachingId === $row->id)
                                <div class="mt-4 grid gap-3 rounded-xl bg-slate-50 p-3 sm:grid-cols-12" data-teach-form>
                                    <x-admin.select wire:model="ruleId" name="ruleId" :label="__($t.'.rule')" :options="$this->ruleOptions" :placeholder="__($t.'.choose_rule')" class="sm:col-span-5" />
                                    <x-admin.select wire:model="matchType" name="matchType" :label="__('chatbot_admin.rules.fields.match_type')" :options="collect(\App\Enums\KeywordMatch::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" class="sm:col-span-3" />
                                    <x-admin.input wire:model="weight" name="weight" type="number" min="1" max="100" :label="__('chatbot_admin.rules.fields.weight')" class="sm:col-span-2" />
                                    <div class="flex items-end gap-2 sm:col-span-2">
                                        <x-admin.button type="button" size="sm" icon="check" wire:click="addKeyword" loading="addKeyword">{{ __('admin.common.save') }}</x-admin.button>
                                        <x-admin.button type="button" size="sm" variant="ghost" wire:click="closeTeach">{{ __('admin.common.cancel') }}</x-admin.button>
                                    </div>
                                </div>
                            @endif
                        @endif
                    </li>
                @endforeach
            </ul>

            <div class="mt-4">{{ $items->links() }}</div>
        @endif
    </div>
</div>
