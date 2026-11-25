@php($t = 'office.chats')

{{-- Virtual office → Business chats (plan 41 §8.2). Rows through BusinessChat::visibleTo() only. --}}
<div wire:poll.30s.visible data-office-chats>
    <x-admin.page-header :title="__($t.'.title')" :subtitle="$seesAll ? __($t.'.subtitle_all') : __($t.'.subtitle_mine')">
        <x-slot:breadcrumbs>{{ __('menu.groups.office') }} <span class="text-slate-300">/</span> {{ __($t.'.title') }}</x-slot:breadcrumbs>
    </x-admin.page-header>

    <div class="mt-4 flex flex-wrap items-end gap-3">
        <x-admin.select wire:model.live="role" :label="__($t.'.filters.role')" :placeholder="__($t.'.filters.all')" :options="collect(\App\Enums\AgentRole::routable())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" class="w-44" />
        @if ($seesAll)
            <x-admin.select wire:model.live="assignee" :label="__($t.'.filters.assignee')" :placeholder="__($t.'.filters.all')" :options="['none' => __($t.'.unassigned')] + $assignable->pluck('name', 'id')->all()" class="w-52" />
        @endif
        <x-admin.select wire:model.live="lead" :label="__($t.'.filters.lead')" :placeholder="__($t.'.filters.all')" :options="['open' => __($t.'.filters.lead_open'), 'none' => __($t.'.filters.lead_none')]" class="w-44" />
        <x-admin.toggle wire:model.live="hasDraft" :label="__($t.'.filters.has_draft')" class="pb-2" />
        <x-admin.toggle wire:model.live="needsPhone" :label="__($t.'.filters.needs_phone')" class="pb-2" />
        @if ($filtering)
            <button type="button" wire:click="clearFilters" class="pb-2.5 text-sm font-semibold text-brand-600 hover:text-brand-700">{{ __($t.'.filters.clear') }}</button>
        @endif
    </div>

    <div class="relative mt-4">
        <div wire:loading.delay wire:target="role,assignee,lead,hasDraft,needsPhone,clearFilters,gotoPage,previousPage,nextPage" class="absolute inset-0 z-10 rounded-2xl bg-white/50" aria-hidden="true"></div>

        @if ($chats->isEmpty())
            <x-admin.empty-state icon="message" :title="$filtering ? __($t.'.no_results') : __($t.'.empty_title')" :body="$filtering ? __($t.'.no_results_body') : __($t.'.empty_body')" />
        @else
            <x-admin.table>
                <x-slot:caption>{{ __($t.'.title') }}</x-slot:caption>
                <x-slot:head>
                    <th class="py-3 pl-5 pr-4">{{ __($t.'.columns.client') }}</th>
                    <th class="px-4 py-3">{{ __($t.'.columns.analysis') }}</th>
                    <th class="px-4 py-3">{{ __($t.'.columns.assignee') }}</th>
                    <th class="whitespace-nowrap px-4 py-3">{{ __($t.'.columns.last_message') }}</th>
                    <th class="w-16 py-3 pr-5"><span class="sr-only">{{ __('admin.common.actions') }}</span></th>
                </x-slot:head>
                @foreach ($chats as $chat)
                    @php($analysis = $latestAnalyses->get($chat->id))
                    <tr wire:key="office-chat-{{ $chat->id }}" class="hover:bg-slate-50/70" data-office-chat="{{ $chat->id }}">
                        <td class="max-w-[18rem] py-3 pl-5 pr-4">
                            <a href="{{ route('admin.office.chats.show', $chat) }}" wire:navigate @class(['block truncate hover:text-brand-700', 'font-semibold' => $chat->unread_count > 0, 'font-medium' => $chat->unread_count === 0])>{{ $chat->displayName() }}</a>
                            <span class="block truncate text-xs text-slate-500">{{ $analysis?->topic ?? __('admin.common.empty_value') }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="flex flex-wrap gap-1">
                                @if ($analysis)
                                    <x-admin.badge :color="$analysis->status->color()">{{ $analysis->status->label() }}</x-admin.badge>
                                    @if ($analysis->role)<x-admin.badge color="violet">{{ $analysis->role->label() }}</x-admin.badge>@endif
                                    @if ($analysis->lead_action === \App\Models\BusinessChatAnalysis::LEAD_NEEDS_PHONE)<x-admin.badge color="amber">{{ __($t.'.needs_phone') }}</x-admin.badge>@endif
                                @else
                                    <x-admin.badge color="muted">{{ __($t.'.not_analysed') }}</x-admin.badge>
                                @endif
                                @if ($chat->lead && $chat->lead->isOpen())<x-admin.badge color="brand">{{ __($t.'.lead_open') }}</x-admin.badge>@endif
                            </span>
                        </td>
                        <td class="max-w-[12rem] truncate px-4 py-3 text-slate-600">{{ $chat->assignee?->name ?? __($t.'.unassigned') }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-slate-500">{{ $chat->last_message_at ? \App\Support\DateFormat::iso($chat->last_message_at->copy()->setTimezone(config('app.timezone')), 'D MMM, HH:mm') : __('admin.common.empty_value') }}</td>
                        <td class="py-3 pr-5 text-right">
                            <a href="{{ route('admin.office.chats.show', $chat) }}" wire:navigate class="inline-grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="{{ __($t.'.open') }}"><x-admin.icon name="chevron-right" size="size-4" /></a>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>

            <x-admin.mobile-list class="mt-0">
                @foreach ($chats as $chat)
                    @php($analysis = $latestAnalyses->get($chat->id))
                    <article wire:key="office-chat-card-{{ $chat->id }}" class="rounded-2xl border border-slate-200 bg-white p-3.5">
                        <a href="{{ route('admin.office.chats.show', $chat) }}" wire:navigate class="block truncate text-sm font-semibold">{{ $chat->displayName() }}</a>
                        <p class="mt-0.5 truncate text-xs text-slate-500">{{ $chat->assignee?->name ?? __($t.'.unassigned') }} · {{ $analysis?->topic ?? __($t.'.not_analysed') }}</p>
                    </article>
                @endforeach
            </x-admin.mobile-list>

            <div class="mt-4">{{ $chats->links() }}</div>
        @endif
    </div>
</div>
