@php($t = 'telegram_inbox')

{{-- Telegram → Chats (plan 14 v2 §9, plan 21 A1). Polls every 15 s while the tab is visible. --}}
<div wire:poll.15s.visible data-telegram-chats>
    <x-admin.page-header :title="__($t.'.title')" :subtitle="__($t.'.subtitle')">
        <x-slot:breadcrumbs>{{ __('menu.groups.chatbot') }} <span class="text-slate-300">/</span> {{ __($t.'.title') }}</x-slot:breadcrumbs>
    </x-admin.page-header>

    <div class="mt-4 flex flex-wrap items-center gap-2">
        <label class="relative min-w-0 flex-1 basis-full sm:basis-auto">
            <span class="sr-only">{{ __('admin.common.search') }}</span>
            <x-admin.icon name="search" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input wire:model.live.debounce.300ms="search" type="search" maxlength="100" placeholder="{{ __($t.'.search_placeholder') }}" class="h-11 w-full rounded-lg border border-slate-200 bg-white pl-10 pr-3 text-base outline-none placeholder:text-slate-400 focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 sm:text-sm lg:h-10">
        </label>
        <div class="flex flex-wrap rounded-lg border border-slate-200 bg-white p-1" role="group" aria-label="{{ __($t.'.filters.scope') }}">
            @foreach (\App\Livewire\Admin\Telegram\Chats\Index::SCOPES as $value)
                <button type="button" wire:click="setScope('{{ $value }}')" aria-pressed="{{ $currentScope === $value ? 'true' : 'false' }}" data-scope="{{ $value }}" @class(['h-8 rounded-md px-3 text-sm font-semibold', 'bg-slate-900 text-white' => $currentScope === $value, 'text-slate-600 hover:text-slate-900' => $currentScope !== $value])>{{ __($t.'.scopes.'.$value) }}</button>
            @endforeach
        </div>
        <label class="relative flex-1 sm:flex-none">
            <span class="sr-only">{{ __($t.'.filters.status') }}</span>
            <select wire:model.live="status" class="h-11 w-full appearance-none rounded-lg border border-slate-200 bg-white pl-3 pr-8 text-sm font-semibold text-slate-700 outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 lg:h-10">
                <option value="">{{ __($t.'.filters.status_all') }}</option>
                @foreach (\App\Enums\ConversationStatus::cases() as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                @endforeach
            </select>
            <x-admin.icon name="chevron-down" size="size-4" class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400" />
        </label>
        @if ($filtering)
            <button type="button" wire:click="clearFilters" class="px-1 text-sm font-semibold text-brand-600 hover:text-brand-700">{{ __($t.'.filters.clear') }}</button>
        @endif
    </div>

    <div class="relative mt-4">
        <div wire:loading.delay wire:target="search,status,setScope,clearFilters,gotoPage,previousPage,nextPage" class="absolute inset-0 z-10 rounded-2xl bg-white/50" aria-hidden="true"></div>

        @if ($items->isEmpty())
            @if ($filtering)
                <x-admin.empty-state icon="search" :title="__($t.'.no_results')" :body="__($t.'.no_results_body')">
                    <x-admin.button variant="secondary" wire:click="clearFilters">{{ __($t.'.filters.clear') }}</x-admin.button>
                </x-admin.empty-state>
            @else
                <x-admin.empty-state icon="message" :title="__($t.'.empty_title')" :body="__($t.'.empty_body')" />
            @endif
        @else
            <x-admin.table>
                <x-slot:caption>{{ __($t.'.title') }}</x-slot:caption>
                <x-slot:head>
                    <th class="py-3 pl-5 pr-4">{{ __($t.'.columns.customer') }}</th>
                    <th class="px-4 py-3">{{ __($t.'.columns.status') }}</th>
                    <th class="px-4 py-3">{{ __($t.'.columns.manager') }}</th>
                    <th class="whitespace-nowrap px-4 py-3">{{ __($t.'.columns.last_message') }}</th>
                    <th class="w-16 py-3 pr-5"><span class="sr-only">{{ __('admin.common.actions') }}</span></th>
                </x-slot:head>
                @foreach ($items as $chat)
                    @php($name = \App\Support\Telegram\InboxMessage::displayName($chat))
                    <tr wire:key="chat-row-{{ $chat->id }}" @class(['hover:bg-slate-50/70', 'bg-amber-50/60' => in_array($chat->id, $stuck, true)]) data-chat="{{ $chat->id }}">
                        <td class="max-w-[18rem] py-3 pl-5 pr-4">
                            <a href="{{ route('admin.telegram.chats.show', $chat->id) }}" wire:navigate @class(['block truncate hover:text-brand-700', 'font-semibold' => $chat->unread_count > 0, 'font-medium' => $chat->unread_count === 0])>{{ $name !== '' ? $name : __($t.'.unnamed') }}</a>
                            <span class="block truncate text-xs text-slate-500">
                                @if ($chat->username){{ '@'.$chat->username }} · @endif{{ $chat->declared_company ?: ($chat->phone ? \App\Support\Phone::display($chat->phone) : __('admin.common.empty_value')) }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="flex flex-wrap gap-1">
                                @include('livewire.admin.telegram.chats.partials.badges', ['chat' => $chat, 'stuck' => $stuck, 'marksInactiveManagers' => $marksInactiveManagers])
                            </span>
                        </td>
                        <td class="max-w-[12rem] truncate px-4 py-3 text-slate-600">{{ $chat->manager?->name ?? __($t.'.unassigned') }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-slate-500">{{ $chat->last_message_at ? \App\Support\DateFormat::iso($chat->last_message_at->copy()->setTimezone(config('app.timezone')), 'D MMM, HH:mm') : __('admin.common.empty_value') }}</td>
                        <td class="py-3 pr-5 text-right">
                            <a href="{{ route('admin.telegram.chats.show', $chat->id) }}" wire:navigate class="inline-grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="{{ __($t.'.open') }}"><x-admin.icon name="chevron-right" size="size-4" /></a>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>

            <x-admin.mobile-list class="mt-0">
                @foreach ($items as $chat)
                    @php($name = \App\Support\Telegram\InboxMessage::displayName($chat))
                    <article wire:key="chat-card-{{ $chat->id }}" @class(['rounded-2xl border bg-white p-3.5', 'border-amber-300' => in_array($chat->id, $stuck, true), 'border-slate-200' => ! in_array($chat->id, $stuck, true)])>
                        <a href="{{ route('admin.telegram.chats.show', $chat->id) }}" wire:navigate class="block truncate text-sm font-semibold">{{ $name !== '' ? $name : __($t.'.unnamed') }}</a>
                        <p class="mt-0.5 truncate text-xs text-slate-500">{{ $chat->manager?->name ?? __($t.'.unassigned') }} · {{ $chat->last_message_at ? \App\Support\DateFormat::iso($chat->last_message_at->copy()->setTimezone(config('app.timezone')), 'D MMM, HH:mm') : __('admin.common.empty_value') }}</p>
                        <span class="mt-2 flex flex-wrap gap-1">
                            @include('livewire.admin.telegram.chats.partials.badges', ['chat' => $chat, 'stuck' => $stuck, 'marksInactiveManagers' => $marksInactiveManagers])
                        </span>
                    </article>
                @endforeach
            </x-admin.mobile-list>

            <div class="mt-4">{{ $items->links() }}</div>
        @endif
    </div>
</div>
