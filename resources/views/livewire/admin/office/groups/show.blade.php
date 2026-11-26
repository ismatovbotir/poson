@php
    $t = 'office.groups.show';
    $unreadable = \App\Casts\EncryptedText::UNREADABLE;
    $tz = config('app.timezone');
@endphp

{{-- Virtual office → group (plan 44 §2). Read-only: nothing here sends anything to Telegram. All values escaped, no autolinks. --}}
<div @if ($pending) wire:poll.15s.visible @endif data-office-group-show>
    <x-admin.page-header :title="$group->title ?? __('office.groups.untitled', ['id' => $group->id])" :back-href="route('admin.office.groups')" :back-label="__('office.groups.title')">
        <x-slot:actions>
            @if ($canAnalyse && $group->isTracked())
                <x-admin.button icon="sparkles" wire:click="analyse" loading="analyse" :disabled="$pending || ! $analysable" data-action="analyse">{{ __('office.show.analyse_now') }}</x-admin.button>
            @endif
            @if ($canMark && $group->isTracked())
                <x-admin.button variant="secondary" icon="restore" wire:click="refreshAdmins" loading="refreshAdmins" data-action="refresh-admins">{{ __($t.'.refresh_admins') }}</x-admin.button>
            @endif
            @if ($canMark && $group->isTracked())
                <x-admin.button variant="danger" icon="x" wire:click="untrack" wire:confirm="{{ __('office.groups.confirm_untrack') }}" loading="untrack" data-action="untrack">{{ __('office.groups.untrack') }}</x-admin.button>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mt-2 flex flex-wrap items-center gap-1.5 text-sm text-slate-600">
        <x-admin.badge :color="$group->status->color()" pill dot>{{ $group->status->label() }}</x-admin.badge>
        <span>{{ __('office.groups.columns.assignee') }}: <strong>{{ $group->assignee?->name ?? __('office.groups.unassigned') }}</strong></span>
        @if ($group->customer)
            · <span>{{ __('office.groups.customer') }}: <strong>{{ $group->customer->name }}</strong></span>
        @endif
        · <span>{{ __('office.groups.columns.last_activity') }}: {{ $group->last_message_at ? \App\Support\DateFormat::iso($group->last_message_at->copy()->setTimezone($tz), 'D MMM, HH:mm') : __('admin.common.empty_value') }}</span>
    </div>

    @if (! $group->bot_is_admin)
        <p class="mt-3 flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2.5 text-sm text-amber-800" role="note" data-bot-warning>
            <x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.bot_warning') }}
        </p>
    @endif

    @error('analyse')<p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800" role="alert" data-analyse-error>{{ $message }}</p>@enderror

    @if ($errors->any() && ! $errors->has('analyse'))
        <div class="mt-3 rounded-lg bg-red-50 px-3 py-2.5 text-sm text-danger" role="alert" data-group-errors>
            @foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach
        </div>
    @endif

    <div class="mt-5 grid gap-4 lg:gap-6 xl:grid-cols-5">
        <div class="space-y-4 lg:space-y-6 xl:col-span-3">
            {{-- Members --}}
            <x-admin.card :title="__($t.'.members')" :description="__($t.'.members_hint')" :padding="false">
                @if ($canMark && $group->isTracked())
                    <div class="flex flex-wrap items-end gap-3 border-b border-slate-100 px-4 py-3.5 sm:px-6" data-bulk-role>
                        <x-admin.select wire:model.live="bulkRole" name="bulkRole" :label="__($t.'.set_role')" :placeholder="__($t.'.choose_role')" :options="collect($roles)->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" class="w-44" />
                        @if ($bulkRole === 'employee')
                            <x-admin.select wire:model="bulkStaffId" name="bulkStaffId" :label="__($t.'.staff_user')" :placeholder="__($t.'.no_staff_user')" :options="$assignable->pluck('name', 'id')->all()" :hint="__($t.'.staff_single')" class="w-56" />
                        @elseif ($bulkRole === 'client')
                            <div class="w-64">
                                <p class="text-sm font-semibold">{{ __('office.groups.customer') }}</p>
                                @if ($bulkCustomerId !== null)
                                    <p class="mt-1.5 flex items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm"><span class="truncate font-medium">{{ $customerName ?? '#'.$bulkCustomerId }}</span><button type="button" wire:click="clearCustomer" class="text-xs font-semibold text-brand-600">{{ __('office.groups.customer_clear') }}</button></p>
                                @else
                                    <x-admin.input type="search" wire:model.live.debounce.300ms="customerSearch" name="customerSearch" :placeholder="__('office.groups.customer_search')" class="mt-1.5" autocomplete="off" />
                                    @if (mb_strlen(trim($customerSearch)) >= \App\Support\Office\CustomerCandidates::MIN_TERM)
                                        <ul class="mt-1 max-h-40 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200" data-customer-candidates>
                                            @forelse ($candidates as $candidate)
                                                <li wire:key="bulk-cust-{{ $candidate['id'] }}"><button type="button" wire:click="pickCustomer({{ $candidate['id'] }})" class="w-full truncate px-3 py-2 text-left text-sm hover:bg-slate-50">{{ $candidate['name'] }}</button></li>
                                            @empty
                                                <li class="px-3 py-2 text-sm text-slate-500">{{ __('office.groups.customer_none') }}</li>
                                            @endforelse
                                        </ul>
                                    @endif
                                @endif
                            </div>
                        @endif
                        <x-admin.button icon="check" wire:click="setRole" loading="setRole" x-bind:disabled="$wire.selected.length === 0 || ! $wire.bulkRole" data-action="set-role"><span x-text="@js(__($t.'.apply', ['count' => ':n'])).replace(':n', $wire.selected.length)">{{ __($t.'.apply', ['count' => 0]) }}</span></x-admin.button>
                    </div>
                @endif

                @if ($members->isEmpty())
                    <div class="p-4 sm:p-6"><x-admin.empty-state icon="users" :title="__($t.'.no_members')" :body="__($t.'.no_members_body')" /></div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm" data-members>
                            <caption class="sr-only">{{ __($t.'.members') }}</caption>
                            <thead class="border-b border-slate-200 bg-slate-50/70 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    @if ($canMark)<th class="w-10 py-3 pl-5"><span class="sr-only">{{ __($t.'.select') }}</span></th>@endif
                                    <th class="px-4 py-3">{{ __($t.'.columns.name') }}</th>
                                    <th class="px-4 py-3">{{ __($t.'.columns.role') }}</th>
                                    <th class="px-4 py-3">{{ __($t.'.columns.linked') }}</th>
                                    @if ($canSeePhone)<th class="px-4 py-3">{{ __($t.'.columns.phone') }}</th>@endif
                                    <th class="whitespace-nowrap px-4 py-3">{{ __($t.'.columns.last_seen') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __($t.'.columns.messages') }}</th>
                                    @if ($canMark)<th class="py-3 pr-5"><span class="sr-only">{{ __('admin.common.actions') }}</span></th>@endif
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($members as $member)
                                    <tr wire:key="member-{{ $member->id }}" data-member="{{ $member->id }}">
                                        @if ($canMark)
                                            <td class="py-3 pl-5">
                                                @unless ($member->is_bot || $member->role === \App\Enums\GroupMemberRole::Bot)
                                                    <input type="checkbox" wire:model="selected" value="{{ $member->id }}" class="size-4 rounded accent-brand-600" aria-label="{{ __($t.'.select') }}">
                                                @endunless
                                            </td>
                                        @endif
                                        <td class="max-w-[14rem] px-4 py-3">
                                            <span class="block truncate font-medium">{{ $member->first_name ?? __('office.groups.no_name') }}</span>
                                            <span class="block truncate text-xs text-slate-500">{{ $member->username ? '@'.$member->username : __('admin.common.empty_value') }}</span>
                                        </td>
                                        <td class="px-4 py-3"><x-admin.badge :color="$member->role->color()">{{ $member->role->label() }}</x-admin.badge></td>
                                        <td class="max-w-[12rem] px-4 py-3 text-slate-600">
                                            <span class="flex flex-wrap gap-1">
                                                @if ($member->staffUser)<x-admin.badge color="sky">{{ $member->staffUser->name }}</x-admin.badge>@endif
                                                @if ($member->customer)<x-admin.badge color="brand">{{ $member->customer->name }}</x-admin.badge>@endif
                                                @if ($member->telegram_user_id)<x-admin.badge color="violet">{{ __($t.'.bot_user') }}</x-admin.badge>@endif
                                                @if (! $member->staffUser && ! $member->customer && ! $member->telegram_user_id)<span class="text-slate-400">{{ __('admin.common.empty_value') }}</span>@endif
                                            </span>
                                        </td>
                                        @if ($canSeePhone)
                                            <td class="whitespace-nowrap px-4 py-3 text-slate-600" data-member-phone>{{ $member->phone ?? __('admin.common.empty_value') }}</td>
                                        @endif
                                        <td class="whitespace-nowrap px-4 py-3 text-slate-500">{{ $member->last_seen_at ? \App\Support\DateFormat::iso($member->last_seen_at->copy()->setTimezone($tz), 'D MMM, HH:mm') : __('admin.common.empty_value') }}</td>
                                        <td class="px-4 py-3 text-right text-slate-600">{{ $member->message_count }}</td>
                                        @if ($canMark)
                                            <td class="whitespace-nowrap py-3 pr-5 text-right">
                                                <x-admin.button size="sm" variant="ghost" icon="trash" wire:click="forget({{ $member->id }})" wire:confirm="{{ __($t.'.forget_confirm') }}" loading="forget" data-action="forget">{{ __($t.'.forget') }}</x-admin.button>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="border-t border-slate-100 px-4 py-3 sm:px-6">{{ $members->links() }}</div>
                @endif
            </x-admin.card>

            {{-- Threads (plan 44 T6): the latest analysis per client member, or one for a customer-linked group. The draft is copy-only, never sent. --}}
            <x-admin.card :title="__($t.'.threads')" :description="__($t.'.threads_hint')" data-threads-panel>
                @if ($pending)
                    <p class="mb-3 flex items-center gap-2 text-sm text-sky-700" role="status" data-analysis-pending><x-admin.icon name="clock" size="size-4" />{{ __('office.show.pending') }}</p>
                @endif
                @if ($group->isTracked() && ! $analysable)
                    <p class="mb-3 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600" data-not-analysable>{{ __($t.'.not_analysable') }}</p>
                @endif
                @forelse ($threads as $thread)
                    <div wire:key="thread-{{ $thread->id }}" @class(['text-sm', 'mt-4 border-t border-slate-100 pt-4' => ! $loop->first]) data-thread="{{ $thread->id }}">
                        <div class="flex flex-wrap items-center gap-1.5">
                            @if ($thread->role)<x-admin.badge color="violet">{{ $thread->role->label() }}</x-admin.badge>@endif
                            <x-admin.badge color="slate">{{ $thread->client_member_id === null ? __($t.'.thread_group') : ($thread->clientMember?->first_name ?? __('office.groups.no_name')) }}</x-admin.badge>
                            <span class="text-xs text-slate-500">{{ $thread->updated_at?->copy()->setTimezone($tz)->format('d.m H:i') }}</span>
                            @if ($thread->lead)
                                @can('view', $thread->lead)
                                    · <a href="{{ route('admin.leads.show', $thread->lead) }}" wire:navigate class="font-semibold text-brand-700 hover:underline" data-thread-lead>{{ $thread->lead->reference() }}</a>
                                @endcan
                            @endif
                        </div>
                        <dl class="mt-2 space-y-2">
                            <div><dt class="text-slate-500">{{ __('office.show.topic') }}</dt><dd class="mt-0.5 font-medium">{{ $thread->topic ?? __('admin.common.empty_value') }}</dd></div>
                            @foreach (['summary' => $thread->summary, 'next_step' => $thread->next_step] as $field => $value)
                                <div><dt class="text-slate-500">{{ __('office.show.'.$field) }}</dt><dd class="mt-0.5 whitespace-pre-line break-words" data-{{ str_replace('_', '-', $field) }}>{{ \App\Casts\EncryptedText::isReadable($value) ? $value : ($value === $unreadable ? __('office.unreadable') : __('admin.common.empty_value')) }}</dd></div>
                            @endforeach
                            <div>
                                <dt class="text-slate-500">{{ __('office.show.draft') }}</dt>
                                @if ($thread->draft_blocked)
                                    <dd class="mt-0.5 text-amber-700" data-draft-blocked>{{ __('office.show.draft_blocked') }}</dd>
                                @elseif ($thread->draft && $thread->draft !== $unreadable)
                                    <dd class="mt-1 rounded-lg border border-slate-200 bg-slate-50 p-3">
                                        <p class="whitespace-pre-line break-words" data-draft>{{ $thread->draft }}</p>
                                        <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                                            <span class="text-xs text-slate-500">{{ __('office.show.draft_not_sent') }}</span>
                                            <button type="button" x-data="{ copied: false }" x-on:click="navigator.clipboard.writeText($el.dataset.copy).then(() => { copied = true; setTimeout(() => copied = false, 2000) })" data-copy="{{ $thread->draft }}" class="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 hover:text-brand-800">
                                                <x-admin.icon name="copy" size="size-4" /><span x-text="copied ? @js(__('office.show.copied')) : @js(__('office.show.copy'))">{{ __('office.show.copy') }}</span>
                                            </button>
                                        </div>
                                    </dd>
                                @else
                                    <dd class="mt-0.5 text-slate-400">{{ __('office.show.no_draft') }}</dd>
                                @endif
                            </div>
                        </dl>
                    </div>
                @empty
                    @unless ($pending)
                        <p class="text-sm text-slate-500" data-no-threads>{{ __($t.'.no_threads') }}</p>
                    @endunless
                @endforelse
            </x-admin.card>
        </div>

        {{-- Transcript --}}
        <x-admin.card :title="__($t.'.transcript')" :description="__($t.'.transcript_hint')" class="xl:col-span-2" data-transcript>
            @forelse ($messages as $message)
                @php($role = $message->member?->role)
                <div wire:key="gm-{{ $message->id }}" class="mb-2 flex justify-start">
                    <div @class(['max-w-[92%] rounded-2xl px-3.5 py-2 text-sm', 'bg-brand-50 text-slate-800' => $role === \App\Enums\GroupMemberRole::Employee, 'bg-slate-100 text-slate-800' => $role !== \App\Enums\GroupMemberRole::Employee])>
                        <p class="text-xs font-semibold text-slate-500">{{ $message->member?->first_name ?? __('office.groups.no_name') }}@if ($message->member?->username) ({{ '@'.$message->member->username }})@endif · {{ ($message->sent_at ?? $message->created_at)?->copy()->setTimezone($tz)->format('d.m H:i') }}@if ($message->edited_at) · {{ __($t.'.edited') }}@endif</p>
                        @if ($message->kind !== \App\Enums\TelegramMessageKind::Text)
                            <p class="text-xs text-slate-500">[{{ $message->kind->label() }}@if (is_string($message->media['file_name'] ?? null)): {{ \App\Support\Chat\ChatRedactor::clean($message->media['file_name'], 80) }}@endif]</p>
                        @endif
                        @if ($message->text === $unreadable)
                            <p class="italic text-slate-400" data-unreadable>{{ __('office.unreadable') }}</p>
                        @elseif ($message->text !== null)
                            <p class="whitespace-pre-line break-words">{{ $message->text }}</p>
                        @endif
                    </div>
                </div>
            @empty
                <x-admin.empty-state icon="message" :title="__($t.'.no_messages')" :body="__($t.'.no_messages_body')" />
            @endforelse
            <div class="mt-3">{{ $messages->links() }}</div>
        </x-admin.card>
    </div>
</div>
