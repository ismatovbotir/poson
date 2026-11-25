@php($t = 'office.unmarked')

{{-- Virtual office → Unmarked (plan 41 §8.1). Only the stub is shown: nothing is stored before a mark. --}}
<div data-office-unmarked>
    <x-admin.page-header :title="__($t.'.title')" :subtitle="__($t.'.subtitle')">
        <x-slot:breadcrumbs>{{ __('menu.groups.office') }} <span class="text-slate-300">/</span> {{ __($t.'.title') }}</x-slot:breadcrumbs>
    </x-admin.page-header>

    <p class="mt-4 flex max-w-4xl items-start gap-2 rounded-lg bg-slate-50 px-3 py-2.5 text-sm text-slate-600" role="note">
        <x-admin.icon name="info" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.note') }}
    </p>

    {{-- Errors from the mark action (e.g. an assignee who cannot work with office chats) --}}
    @if ($errors->any())
        <div class="mt-4 max-w-4xl rounded-lg bg-red-50 px-3 py-2.5 text-sm text-danger" role="alert" data-mark-errors>
            @foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach
        </div>
    @endif

    <div class="relative mt-4">
        <div wire:loading.delay wire:target="markClient,markEmployee,ignore,gotoPage,previousPage,nextPage" class="absolute inset-0 z-10 rounded-2xl bg-white/50" aria-hidden="true"></div>

        @if ($chats->isEmpty())
            <x-admin.empty-state icon="check" :title="__($t.'.empty_title')" :body="__($t.'.empty_body')" />
        @else
            <div class="space-y-3">
                @foreach ($chats as $chat)
                    <article wire:key="unmarked-{{ $chat->id }}" class="rounded-2xl border border-slate-200 bg-white p-4" data-unmarked-chat="{{ $chat->id }}">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate font-semibold">{{ $chat->displayName() }}</p>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    @if ($chat->username){{ '@'.$chat->username }} · @endif
                                    {{ trans_choice($t.'.waiting', $chat->unread_count, ['count' => $chat->unread_count]) }}
                                    · {{ __($t.'.first_inbound') }}: {{ $chat->first_inbound_at ? \App\Support\DateFormat::iso($chat->first_inbound_at->copy()->setTimezone(config('app.timezone')), 'D MMM, HH:mm') : __('admin.common.empty_value') }}
                                    · {{ __($t.'.last_inbound') }}: {{ $chat->last_inbound_at ? \App\Support\DateFormat::iso($chat->last_inbound_at->copy()->setTimezone(config('app.timezone')), 'D MMM, HH:mm') : __('admin.common.empty_value') }}
                                </p>
                            </div>
                            <x-admin.badge color="amber" pill dot>{{ \App\Enums\BusinessChatKind::Unmarked->label() }}</x-admin.badge>
                        </div>

                        <div class="mt-3 grid gap-3 border-t border-slate-100 pt-3 lg:grid-cols-3">
                            <div class="flex items-end gap-2">
                                <x-admin.select wire:model="assignees.{{ $chat->id }}" :label="__($t.'.assignee')" :placeholder="__($t.'.no_assignee')" :options="$assignable->pluck('name', 'id')->all()" class="min-w-0 flex-1" />
                                <x-admin.button icon="check" wire:click="markClient({{ $chat->id }})" loading="markClient" data-action="client">{{ __($t.'.as_client') }}</x-admin.button>
                            </div>
                            <div class="flex items-end gap-2">
                                <x-admin.select wire:model="staff.{{ $chat->id }}" :label="__($t.'.staff_user')" :placeholder="__($t.'.no_staff_user')" :options="$activeStaff->pluck('name', 'id')->all()" class="min-w-0 flex-1" />
                                <x-admin.button variant="secondary" icon="users" wire:click="markEmployee({{ $chat->id }})" loading="markEmployee" data-action="employee">{{ __($t.'.as_employee') }}</x-admin.button>
                            </div>
                            <div class="flex items-end justify-end">
                                <x-admin.button variant="ghost" icon="x" wire:click="ignore({{ $chat->id }})" wire:confirm="{{ __($t.'.confirm_ignore') }}" loading="ignore" data-action="ignore">{{ __($t.'.ignore') }}</x-admin.button>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="mt-4">{{ $chats->links() }}</div>
        @endif
    </div>
</div>
