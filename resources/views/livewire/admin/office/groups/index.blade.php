@php
    $t = 'office.groups';
    $tz = config('app.timezone');
    $tabs = $seesAll ? \App\Enums\GroupStatus::cases() : [\App\Enums\GroupStatus::Tracked];
@endphp

{{-- Virtual office → Telegram groups (plan 44 §2). Rows through TelegramGroup::visibleTo() only; titles are escaped. --}}
<div data-office-groups>
    <x-admin.page-header :title="__($t.'.title')" :subtitle="$seesAll ? __($t.'.subtitle_all') : __($t.'.subtitle_mine')">
        <x-slot:breadcrumbs>{{ __('menu.groups.office') }} <span class="text-slate-300">/</span> {{ __($t.'.title') }}</x-slot:breadcrumbs>
    </x-admin.page-header>

    @if ($seesAll)
        <div class="mt-4 border-b border-slate-200" role="tablist" aria-label="{{ __($t.'.tabs_label') }}">
            <div class="flex gap-6">
                @foreach ($tabs as $case)
                    <button type="button" role="tab" wire:click="$set('tab', '{{ $case->value }}')" aria-selected="{{ $status === $case ? 'true' : 'false' }}" data-tab="{{ $case->value }}" @class([
                        '-mb-px whitespace-nowrap border-b-2 pb-3 text-sm font-semibold',
                        'border-brand-600 text-slate-900' => $status === $case,
                        'border-transparent text-slate-500 hover:text-slate-700' => $status !== $case,
                    ])>{{ $case->label() }}</button>
                @endforeach
            </div>
        </div>
        <p class="mt-3 flex items-start gap-2 rounded-lg bg-slate-50 px-3 py-2.5 text-sm text-slate-600" role="note">
            <x-admin.icon name="info" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.note_'.$status->value) }}
        </p>
    @endif

    @if ($errors->has('group'))
        <p class="mt-3 rounded-lg bg-red-50 px-3 py-2.5 text-sm text-danger" role="alert">{{ $errors->first('group') }}</p>
    @endif

    <div class="relative mt-4">
        <div wire:loading.delay wire:target="tab,ignore,untrack,gotoPage,previousPage,nextPage" class="absolute inset-0 z-10 rounded-2xl bg-white/50" aria-hidden="true"></div>

        @if ($groups->isEmpty())
            <x-admin.empty-state icon="users" :title="__($t.'.empty_title')" :body="__($t.'.empty_body')" />
        @else
            <x-admin.table>
                <x-slot:caption>{{ __($t.'.title') }}</x-slot:caption>
                <x-slot:head>
                    <th class="py-3 pl-5 pr-4">{{ __($t.'.columns.title') }}</th>
                    <th class="px-4 py-3">{{ __($t.'.columns.bot') }}</th>
                    <th class="px-4 py-3">{{ __($t.'.columns.members') }}</th>
                    <th class="whitespace-nowrap px-4 py-3">{{ __($t.'.columns.last_activity') }}</th>
                    <th class="px-4 py-3">{{ __($t.'.columns.assignee') }}</th>
                    <th class="py-3 pr-5 text-right"><span class="sr-only">{{ __('admin.common.actions') }}</span></th>
                </x-slot:head>
                @foreach ($groups as $group)
                    <tr wire:key="group-{{ $group->id }}" class="hover:bg-slate-50/70" data-group="{{ $group->id }}">
                        <td class="max-w-[22rem] py-3 pl-5 pr-4">
                            @if ($status === \App\Enums\GroupStatus::Tracked)
                                <a href="{{ route('admin.office.groups.show', $group) }}" wire:navigate class="block truncate font-medium hover:text-brand-700">{{ $group->title ?? __($t.'.untitled', ['id' => $group->id]) }}</a>
                            @else
                                <span class="block truncate font-medium">{{ $group->title ?? __($t.'.untitled', ['id' => $group->id]) }}</span>
                            @endif
                            <span class="block truncate text-xs text-slate-500">{{ __($t.'.type.'.$group->type) }}</span>
                        </td>
                        <td class="px-4 py-3">
                            @if ($group->bot_is_admin)
                                <x-admin.badge color="brand" data-bot-admin>{{ __($t.'.bot_admin') }}</x-admin.badge>
                            @else
                                <x-admin.badge color="amber" data-bot-limited><x-admin.icon name="alert" size="size-3.5" />{{ __($t.'.bot_limited') }}</x-admin.badge>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-600">
                            @if ($status === \App\Enums\GroupStatus::Tracked)
                                {{ $group->members_count }}
                            @else
                                <span class="text-xs text-slate-500">{{ trans_choice($t.'.messages_seen', $group->seen_count, ['count' => $group->seen_count]) }}</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-slate-500">{{ $group->last_message_at ? \App\Support\DateFormat::iso($group->last_message_at->copy()->setTimezone($tz), 'D MMM, HH:mm') : __('admin.common.empty_value') }}</td>
                        <td class="max-w-[12rem] truncate px-4 py-3 text-slate-600">{{ $group->assignee?->name ?? __($t.'.unassigned') }}</td>
                        <td class="whitespace-nowrap py-3 pr-5 text-right">
                            @if ($status === \App\Enums\GroupStatus::Tracked)
                                @if ($canMark)
                                    <x-admin.button size="sm" variant="ghost" icon="x" wire:click="untrack({{ $group->id }})" wire:confirm="{{ __($t.'.confirm_untrack') }}" loading="untrack" data-action="untrack">{{ __($t.'.untrack') }}</x-admin.button>
                                @endif
                                <a href="{{ route('admin.office.groups.show', $group) }}" wire:navigate class="inline-grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="{{ __($t.'.open') }}"><x-admin.icon name="chevron-right" size="size-4" /></a>
                            @elseif ($canMark)
                                <x-admin.button size="sm" icon="check" wire:click="openTrack({{ $group->id }})" data-action="track">{{ __($t.'.track') }}</x-admin.button>
                                @if ($status === \App\Enums\GroupStatus::Untracked)
                                    <x-admin.button size="sm" variant="ghost" icon="x" wire:click="ignore({{ $group->id }})" wire:confirm="{{ __($t.'.confirm_ignore') }}" loading="ignore" data-action="ignore">{{ __($t.'.ignore') }}</x-admin.button>
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>

            <x-admin.mobile-list class="mt-0">
                @foreach ($groups as $group)
                    <article wire:key="group-card-{{ $group->id }}" class="rounded-2xl border border-slate-200 bg-white p-3.5">
                        @if ($status === \App\Enums\GroupStatus::Tracked)
                            <a href="{{ route('admin.office.groups.show', $group) }}" wire:navigate class="block truncate text-sm font-semibold">{{ $group->title ?? __($t.'.untitled', ['id' => $group->id]) }}</a>
                        @else
                            <p class="truncate text-sm font-semibold">{{ $group->title ?? __($t.'.untitled', ['id' => $group->id]) }}</p>
                        @endif
                        <p class="mt-0.5 truncate text-xs text-slate-500">{{ $group->assignee?->name ?? __($t.'.unassigned') }} · {{ $group->bot_is_admin ? __($t.'.bot_admin') : __($t.'.bot_limited') }}</p>
                        @if ($status === \App\Enums\GroupStatus::Tracked && $canMark)
                            <div class="mt-2 flex gap-2">
                                <x-admin.button size="sm" variant="ghost" icon="x" wire:click="untrack({{ $group->id }})" wire:confirm="{{ __($t.'.confirm_untrack') }}" loading="untrack">{{ __($t.'.untrack') }}</x-admin.button>
                            </div>
                        @endif
                        @if ($status !== \App\Enums\GroupStatus::Tracked && $canMark)
                            <div class="mt-2 flex gap-2">
                                <x-admin.button size="sm" icon="check" wire:click="openTrack({{ $group->id }})">{{ __($t.'.track') }}</x-admin.button>
                                @if ($status === \App\Enums\GroupStatus::Untracked)
                                    <x-admin.button size="sm" variant="ghost" icon="x" wire:click="ignore({{ $group->id }})" wire:confirm="{{ __($t.'.confirm_ignore') }}" loading="ignore">{{ __($t.'.ignore') }}</x-admin.button>
                                @endif
                            </div>
                        @endif
                    </article>
                @endforeach
            </x-admin.mobile-list>

            <div class="mt-4">{{ $groups->links() }}</div>
        @endif
    </div>

    {{-- Untrack is a separate, password-protected step (plan 44 T7). --}}

    {{-- Track dialog (Admin only) --}}
    @if ($tracking)
        <div class="fixed inset-0 z-[60] flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" aria-labelledby="group-track-title" x-on:keydown.escape.window="$wire.closeTrack()" data-track-dialog>
            <div class="absolute inset-0 bg-slate-900/50" wire:click="closeTrack"></div>
            <form wire:submit="track" x-trap.noscroll="true" novalidate class="relative max-h-[90vh] w-full overflow-y-auto rounded-t-2xl bg-white p-5 pb-[calc(1.25rem+env(safe-area-inset-bottom))] shadow-2xl sm:max-w-lg sm:rounded-2xl sm:pb-5">
                <h2 id="group-track-title" class="text-base font-semibold">{{ __($t.'.track_title', ['title' => $tracking->title ?? __($t.'.untitled', ['id' => $tracking->id])]) }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ __($t.'.track_body') }}</p>

                <div class="mt-4 space-y-4">
                    <x-admin.select wire:model="assigneeId" name="assigneeId" :label="__($t.'.assignee')" :placeholder="__($t.'.choose_assignee')" :options="$assignable->pluck('name', 'id')->all()" required />

                    <div>
                        <p class="text-sm font-semibold">{{ __($t.'.customer') }} <span class="font-normal text-slate-500">({{ __($t.'.optional') }})</span></p>
                        @if ($customerId !== null)
                            <p class="mt-1.5 flex items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm" data-customer-picked>
                                <span class="truncate font-medium">{{ $customerName ?? '#'.$customerId }}</span>
                                <button type="button" wire:click="clearCustomer" class="text-xs font-semibold text-brand-600 hover:text-brand-700">{{ __($t.'.customer_clear') }}</button>
                            </p>
                        @else
                            <x-admin.input type="search" icon="search" wire:model.live.debounce.300ms="customerSearch" name="customerSearch" :placeholder="__($t.'.customer_search')" :hint="__($t.'.customer_search_hint')" class="mt-1.5" autocomplete="off" />
                            @if (mb_strlen(trim($customerSearch)) >= \App\Support\Office\CustomerCandidates::MIN_TERM)
                                <ul class="mt-2 max-h-48 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200" data-customer-candidates>
                                    @forelse ($candidates as $candidate)
                                        <li wire:key="cust-{{ $candidate['id'] }}"><button type="button" wire:click="pickCustomer({{ $candidate['id'] }})" class="w-full truncate px-3 py-2 text-left text-sm hover:bg-slate-50">{{ $candidate['name'] }}</button></li>
                                    @empty
                                        <li class="px-3 py-2 text-sm text-slate-500">{{ __($t.'.customer_none') }}</li>
                                    @endforelse
                                </ul>
                            @endif
                        @endif
                        @error('customerId')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="flex cursor-pointer items-start gap-2.5 text-sm">
                            <input type="checkbox" wire:model="privacyNote" class="mt-0.5 size-4 shrink-0 rounded accent-brand-600" data-privacy-note>
                            <span><span class="font-semibold">{{ __($t.'.privacy_note') }}</span> <span class="text-danger" aria-hidden="true">*</span><span class="mt-0.5 block text-slate-500">{{ __($t.'.privacy_note_hint') }}</span></span>
                        </label>
                        @error('privacyNote')<p class="mt-1 text-sm text-danger" data-privacy-error>{{ $message }}</p>@enderror
                    </div>
                    @error('group')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                </div>

                <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button type="button" wire:click="closeTrack" class="h-11 rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50 sm:h-10">{{ __('admin.common.cancel') }}</button>
                    <x-admin.button type="submit" loading="track" data-action="track-confirm">{{ __($t.'.track') }}</x-admin.button>
                </div>
            </form>
        </div>
    @endif
</div>
