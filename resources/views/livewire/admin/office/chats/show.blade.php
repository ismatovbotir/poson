@php
    $t = 'office.show';
    $unreadable = \App\Casts\EncryptedText::UNREADABLE;
    $tz = config('app.timezone');
@endphp

{{-- Virtual office → chat (plan 41 §8.3). Read-only: nothing here sends anything to Telegram. All values escaped, no autolinks. --}}
<div @if ($pending) wire:poll.15s.visible @endif data-office-chat-show>
    <x-admin.page-header :title="$chat->displayName()" :back-href="route('admin.office.chats.index')" :back-label="__('office.chats.title')">
        <x-slot:actions>
            @if ($canAnalyse)
                <x-admin.button icon="sparkles" wire:click="analyse" loading="analyse" :disabled="$pending || ! $aiEnabled" data-action="analyse">{{ __($t.'.analyse_now') }}</x-admin.button>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mt-2 flex flex-wrap gap-1.5 text-sm text-slate-600">
        <x-admin.badge :color="$chat->kind->color()" pill dot>{{ $chat->kind->label() }}</x-admin.badge>
        <span>{{ __($t.'.assignee') }}: <strong>{{ $chat->assignee?->name ?? __('office.chats.unassigned') }}</strong></span>
        @if ($chat->lead)
            · <a href="{{ route('admin.leads.show', $chat->lead) }}" wire:navigate class="font-semibold text-brand-700 hover:underline">{{ $chat->lead->reference() }}</a>
        @endif
    </div>

    @error('analyse')<p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800" role="alert" data-analyse-error>{{ $message }}</p>@enderror
    @unless ($aiEnabled)
        <p class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600" data-ai-off>{{ __($t.'.ai_off') }}</p>
    @endunless

    <div class="mt-5 grid gap-4 lg:grid-cols-5 lg:gap-6">
        {{-- Transcript --}}
        <x-admin.card :title="__($t.'.transcript')" :description="__($t.'.transcript_hint')" class="lg:col-span-3">
            @if ($hasOlder)
                <div class="mb-3 text-center"><x-admin.button variant="ghost" size="sm" wire:click="loadOlder" loading="loadOlder">{{ __($t.'.load_older') }}</x-admin.button></div>
            @endif
            @forelse ($messages as $message)
                @php($client = $message->author === \App\Enums\BusinessAuthor::Client)
                <div wire:key="bm-{{ $message->id }}" @class(['mb-2 flex', 'justify-start' => $client, 'justify-end' => ! $client])>
                    <div @class(['max-w-[85%] rounded-2xl px-3.5 py-2 text-sm', 'bg-slate-100 text-slate-800' => $client, 'bg-brand-50 text-slate-800' => ! $client])>
                        <p class="text-xs font-semibold text-slate-500">{{ $client ? __($t.'.client') : __($t.'.manager') }} · {{ ($message->sent_at ?? $message->created_at)?->copy()->setTimezone($tz)->format('d.m H:i') }}@if ($message->edited_at) · {{ __($t.'.edited') }}@endif</p>
                        @if ($message->deleted_at)
                            <p class="italic text-slate-400">{{ __($t.'.deleted') }}</p>
                        @else
                            @if ($message->kind !== \App\Enums\TelegramMessageKind::Text)
                                <p class="text-xs text-slate-500">[{{ $message->kind->label() }}@if (is_string($message->media['file_name'] ?? null)): {{ \App\Support\Chat\ChatRedactor::clean($message->media['file_name'], 80) }}@endif]</p>
                            @endif
                            @if ($message->text === $unreadable)
                                <p class="italic text-slate-400" data-unreadable>{{ __('office.unreadable') }}</p>
                            @elseif ($message->text !== null)
                                <p class="whitespace-pre-line break-words">{{ $message->text }}</p>
                            @endif
                        @endif
                    </div>
                </div>
            @empty
                <x-admin.empty-state icon="message" :title="__($t.'.no_messages')" :body="__($t.'.no_messages_body')" />
            @endforelse
        </x-admin.card>

        <div class="space-y-4 lg:col-span-2 lg:space-y-6">
            {{-- Latest analysis --}}
            <x-admin.card :title="__($t.'.analysis')" data-analysis-panel>
                @if ($pending)
                    <p class="mb-3 flex items-center gap-2 text-sm text-sky-700" role="status" data-analysis-pending><x-admin.icon name="clock" size="size-4" />{{ __($t.'.pending') }}</p>
                @endif
                @if ($latest)
                    <dl class="space-y-3 text-sm">
                        <div class="flex flex-wrap gap-1.5">
                            @if ($latest->role)<x-admin.badge color="violet">{{ $latest->role->label() }}</x-admin.badge>@endif
                            <x-admin.badge color="slate">{{ __($t.'.confidence', ['value' => $latest->confidence ?? 0]) }}</x-admin.badge>
                            @if ($latest->needs_human)
                                <x-admin.badge color="red" data-needs-human>{{ __('office.show.needs_human') }}</x-admin.badge>
                            @endif
                        </div>
                        @if ($latest->topic)
                            <div><dt class="text-slate-500">{{ __($t.'.topic') }}</dt><dd class="mt-0.5 font-medium">{{ $latest->topic }}</dd></div>
                        @endif
                        @foreach (['summary' => $latest->summary, 'next_step' => $latest->next_step] as $field => $value)
                            <div><dt class="text-slate-500">{{ __($t.'.'.$field) }}</dt><dd class="mt-0.5 whitespace-pre-line break-words" data-{{ str_replace('_', '-', $field) }}>{{ \App\Casts\EncryptedText::isReadable($value) ? $value : ($value === $unreadable ? __('office.unreadable') : __('admin.common.empty_value')) }}</dd></div>
                        @endforeach
                        <div>
                            <dt class="text-slate-500">{{ __($t.'.draft') }}</dt>
                            @if ($latest->draft_blocked)
                                <dd class="mt-0.5 text-amber-700" data-draft-blocked>{{ __($t.'.draft_blocked') }}</dd>
                            @elseif ($latest->draft_hold && ! $latest->draft_reply)
                                <dd class="mt-0.5 text-amber-700" data-draft-hold>{{ $latest->draft_hold->label() }}</dd>
                            @elseif ($latest->draft_reply && $latest->draft_reply !== $unreadable)
                                @if (in_array($latest->draft_hold, [\App\Enums\DraftHold::LowConfidence, \App\Enums\DraftHold::Language], true))
                                    <dd class="mt-1" data-draft-held>
                                        <details class="rounded-lg border border-amber-200 bg-amber-50 p-3">
                                            <summary class="cursor-pointer text-sm font-semibold text-amber-800">{{ $latest->draft_hold->label() }}</summary>
                                            <div class="mt-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
                                    <p class="whitespace-pre-line break-words" data-draft>{{ $latest->draft_reply }}</p>
                                    <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                                        <span class="text-xs text-slate-500">{{ __($t.'.draft_not_sent') }}</span>
                                        <button type="button" x-data="{ copied: false }" x-on:click="navigator.clipboard.writeText($el.dataset.copy).then(() => { copied = true; setTimeout(() => copied = false, 2000) })" data-copy="{{ $latest->draft_reply }}" class="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 hover:text-brand-800">
                                            <x-admin.icon name="copy" size="size-4" /><span x-text="copied ? @js(__($t.'.copied')) : @js(__($t.'.copy'))">{{ __($t.'.copy') }}</span>
                                        </button>
                                    </div>
                                </div>
                                        </details>
                                    </dd>
                                @else
                                    <dd class="mt-1 rounded-lg border border-slate-200 bg-slate-50 p-3">
                                    <p class="whitespace-pre-line break-words" data-draft>{{ $latest->draft_reply }}</p>
                                    <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                                        <span class="text-xs text-slate-500">{{ __($t.'.draft_not_sent') }}</span>
                                        <button type="button" x-data="{ copied: false }" x-on:click="navigator.clipboard.writeText($el.dataset.copy).then(() => { copied = true; setTimeout(() => copied = false, 2000) })" data-copy="{{ $latest->draft_reply }}" class="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 hover:text-brand-800">
                                            <x-admin.icon name="copy" size="size-4" /><span x-text="copied ? @js(__($t.'.copied')) : @js(__($t.'.copy'))">{{ __($t.'.copy') }}</span>
                                        </button>
                                    </div>
                                </dd>
                                @endif
                            @else
                                <dd class="mt-0.5 text-slate-400">{{ __($t.'.no_draft') }}</dd>
                            @endif
                            @if ($latest->draft_reply && ! $latest->draft_blocked && $latest->draft_reply !== $unreadable)
                                @can('analyse', $chat)
                                    <x-admin.draft-feedback prefix="feedback" submit="submitFeedback" />
                                @endcan
                            @endif
                        </div>
                        @php($fields = (array) $latest->lead_fields)
                        @if (! empty($fields))
                            <div data-lead-fields>
                                <dt class="text-slate-500">{{ __($t.'.lead_hints') }}</dt>
                                <dd class="mt-0.5 space-y-0.5">
                                    @if (! empty($fields['intent']))<p>{{ __($t.'.intent') }}: {{ __('office.intents.'.$fields['intent']) }}</p>@endif
                                    @if (! empty($fields['products']))<p>{{ __($t.'.products') }}: {{ implode(', ', (array) $fields['products']) }}</p>@endif
                                    @if (! empty($fields['device_brand']) || ! empty($fields['device_model']))<p>{{ __($t.'.device') }}: {{ trim(($fields['device_brand'] ?? '').' '.($fields['device_model'] ?? '')) }}</p>@endif
                                    @if (! empty($fields['urgency']))<p>{{ __($t.'.urgency') }}: {{ __('office.urgencies.'.$fields['urgency']) }}</p>@endif
                                </dd>
                            </div>
                        @endif
                        @if ($latest->lead_action)
                            <p class="text-xs text-slate-500" data-lead-action="{{ $latest->lead_action }}">{{ __('office.lead_actions.'.$latest->lead_action) }}</p>
                        @endif
                    </dl>
                @elseif (! $pending)
                    <p class="text-sm text-slate-500">{{ __($t.'.no_analysis') }}</p>
                @endif
            </x-admin.card>

            {{-- Create lead (needs a phone staff confirm; a suggestion found in the text is only pre-filled) --}}
            @if ($needsLead)
                <x-admin.card :title="__($t.'.create_lead')" :description="__($t.'.create_lead_hint')" data-create-lead>
                    <form wire:submit="createLead" class="space-y-3">
                        <x-admin.input wire:model="leadPhone" :label="__('crm.leads.fields.phone')" inputmode="tel" maxlength="32" />
                        <x-admin.select wire:model="leadType" :label="__($t.'.lead_type')" :options="[\App\Enums\LeadType::Quote->value => \App\Enums\LeadType::Quote->label(), \App\Enums\LeadType::ServiceRequest->value => \App\Enums\LeadType::ServiceRequest->label()]" />
                        <x-admin.button type="submit" icon="plus" loading="createLead">{{ __($t.'.create_lead') }}</x-admin.button>
                    </form>
                </x-admin.card>
            @endif

            {{-- Assignment and marking --}}
            @if ($canAssign || $canMark)
                <x-admin.card :title="__($t.'.manage')">
                    <div class="space-y-4">
                        @if ($canAssign)
                            <form wire:submit="assign" class="flex items-end gap-2">
                                <x-admin.select wire:model="assignTarget" :label="__($t.'.assignee')" :placeholder="__('office.chats.unassigned')" :options="$assignable->pluck('name', 'id')->all()" class="min-w-0 flex-1" />
                                <x-admin.button type="submit" variant="secondary" loading="assign" data-action="assign">{{ __($t.'.assign') }}</x-admin.button>
                            </form>
                        @endif
                        @if ($canMark)
                            <form wire:submit="remark" class="space-y-2">
                                <x-admin.select wire:model.live="markKind" :label="__($t.'.remark')" :placeholder="__($t.'.remark_choose')" :options="collect(\App\Enums\BusinessChatKind::markable())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" />
                                @if ($markKind !== '' && $markKind !== 'client')
                                    <p class="text-xs text-amber-700">{{ __($t.'.remark_warning') }}</p>
                                @endif
                                <x-admin.button type="submit" variant="secondary" loading="remark" data-action="remark">{{ __($t.'.remark_save') }}</x-admin.button>
                            </form>
                        @endif
                    </div>
                </x-admin.card>
            @endif

            {{-- History --}}
            <x-admin.card :title="__($t.'.history')">
                @forelse ($analyses as $row)
                    <div wire:key="an-{{ $row->id }}" class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 py-2 text-xs last:border-0">
                        <span class="flex items-center gap-1.5"><x-admin.badge :color="$row->status->color()">{{ $row->status->label() }}</x-admin.badge>{{ $row->role?->label() }}</span>
                        <span class="text-slate-500">{{ $row->created_at?->copy()->setTimezone($tz)->format('d.m H:i') }} · {{ __($t.'.versions', ['router' => $row->routerVersion?->version ?? '–', 'agent' => $row->agentVersion?->version ?? '–']) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">{{ __($t.'.no_analysis') }}</p>
                @endforelse
            </x-admin.card>
        </div>
    </div>
</div>
