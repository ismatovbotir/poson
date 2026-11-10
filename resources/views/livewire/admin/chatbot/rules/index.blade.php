@php
    $t = 'chatbot_admin.rules';
    $canCreate = auth()->user()->can('create', \App\Models\ChatRule::class);
    $canUpdate = auth()->user()->can('chatbot.rules');
    $selectClass = 'h-11 w-full appearance-none rounded-lg border border-slate-200 bg-white pl-3 pr-9 text-sm font-semibold text-slate-700 outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 lg:h-10';
@endphp

<div data-chatbot-rules>
    <x-admin.page-header :title="__($t.'.title')" :subtitle="__($t.'.subtitle')">
        <x-slot:breadcrumbs>{{ __('menu.groups.chatbot') }} <span class="text-slate-300">/</span> {{ __($t.'.title') }}</x-slot:breadcrumbs>
        @if ($canCreate)
            <x-slot:actions>
                <x-admin.button :href="route('admin.chatbot.rules.create')" wire:navigate icon="plus">{{ __($t.'.create') }}</x-admin.button>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    <div class="mt-5 flex flex-wrap items-center gap-2">
        <label class="relative min-w-0 flex-1 basis-full sm:basis-auto">
            <span class="sr-only">{{ __('admin.common.search') }}</span>
            <x-admin.icon name="search" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __($t.'.search_placeholder') }}" class="h-11 w-full rounded-lg border border-slate-200 bg-white pl-10 pr-3 text-base outline-none placeholder:text-slate-400 focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 sm:text-sm lg:h-10">
        </label>
        <label class="relative flex-1 sm:flex-none">
            <span class="sr-only">{{ __($t.'.fields.action') }}</span>
            <select wire:model.live="action" class="{{ $selectClass }}">
                <option value="">{{ __($t.'.action_all') }}</option>
                @foreach (\App\Enums\ChatAction::cases() as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                @endforeach
            </select>
            <x-admin.icon name="chevron-down" size="size-4" class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400" />
        </label>
        <label class="relative flex-1 sm:flex-none">
            <span class="sr-only">{{ __('admin.catalog.status') }}</span>
            <select wire:model.live="status" class="{{ $selectClass }}">
                <option value="">{{ __('admin.catalog.status_all') }}</option>
                <option value="active">{{ __($t.'.status_active') }}</option>
                <option value="inactive">{{ __($t.'.status_inactive') }}</option>
            </select>
            <x-admin.icon name="chevron-down" size="size-4" class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400" />
        </label>
    </div>

    <div class="relative mt-4">
        <div wire:loading.delay class="absolute inset-0 z-10 rounded-2xl bg-white/50" aria-hidden="true"></div>

        @if ($items->isEmpty())
            @if ($filtering)
                <x-admin.empty-state icon="search" :title="__('admin.catalog.no_results')" :body="__('admin.catalog.no_results_body')">
                    <x-admin.button variant="secondary" wire:click="clearFilters">{{ __('admin.catalog.clear_filters') }}</x-admin.button>
                </x-admin.empty-state>
            @else
                <x-admin.empty-state icon="message" :title="__($t.'.empty_title')" :body="__($t.'.empty_body')">
                    @if ($canCreate)
                        <x-admin.button :href="route('admin.chatbot.rules.create')" wire:navigate icon="plus">{{ __($t.'.create') }}</x-admin.button>
                    @endif
                </x-admin.empty-state>
            @endif
        @else
            <x-admin.table>
                <x-slot:caption>{{ __($t.'.title') }}</x-slot:caption>
                <x-slot:head>
                    <th class="py-3 pl-5 pr-4">{{ __($t.'.columns.rule') }}</th>
                    <th class="px-4 py-3">{{ __($t.'.fields.action') }}</th>
                    <th class="px-4 py-3">{{ __($t.'.columns.channels') }}</th>
                    <th class="px-4 py-3 text-right">{{ __($t.'.columns.keywords') }}</th>
                    <th class="px-4 py-3">{{ __('admin.catalog.status') }}</th>
                    <th class="w-24 py-3 pr-5"><span class="sr-only">{{ __('admin.common.actions') }}</span></th>
                </x-slot:head>
                @foreach ($items as $rule)
                    <tr wire:key="rule-row-{{ $rule->id }}" class="hover:bg-slate-50/70" data-rule-row="{{ $rule->key }}">
                        <td class="py-3 pl-5 pr-4">
                            <a href="{{ route('admin.chatbot.rules.edit', $rule) }}" wire:navigate class="font-semibold text-slate-900 hover:text-brand-700">{{ $rule->name }}</a>
                            <span class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
                                <code>{{ $rule->key }}</code>
                                @if ($rule->is_system)<x-admin.badge color="outline">{{ __($t.'.system') }}</x-admin.badge>@endif
                                @if ($rule->in_menu)<x-admin.badge color="sky">{{ __($t.'.in_menu') }}</x-admin.badge>@endif
                            </span>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $rule->action->label() }}</td>
                        <td class="px-4 py-3 text-xs text-slate-600">{{ collect([$rule->on_web ? __('enums.chat_channel.web') : null, $rule->on_telegram ? __('enums.chat_channel.telegram') : null])->filter()->implode(', ') ?: '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-slate-600">{{ $rule->keywords_count }}</td>
                        <td class="px-4 py-3">
                            @if ($canUpdate)
                                <button type="button" wire:click="toggle({{ $rule->id }})" role="switch" aria-checked="{{ $rule->is_active ? 'true' : 'false' }}" aria-label="{{ __($t.'.toggle').': '.$rule->name }}" class="inline-flex" data-toggle-rule="{{ $rule->id }}">
                                    <x-admin.badge :color="$rule->is_active ? 'brand' : 'muted'" pill dot>{{ $rule->is_active ? __($t.'.status_active') : __($t.'.status_inactive') }}</x-admin.badge>
                                </button>
                            @else
                                <x-admin.badge :color="$rule->is_active ? 'brand' : 'muted'" pill dot>{{ $rule->is_active ? __($t.'.status_active') : __($t.'.status_inactive') }}</x-admin.badge>
                            @endif
                        </td>
                        <td class="py-3 pr-5">@include('livewire.admin.chatbot.rules.partials.actions', ['rule' => $rule])</td>
                    </tr>
                @endforeach
            </x-admin.table>

            <x-admin.mobile-list>
                @foreach ($items as $rule)
                    <article wire:key="rule-card-{{ $rule->id }}" class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-3.5">
                        <a href="{{ route('admin.chatbot.rules.edit', $rule) }}" wire:navigate class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-semibold">{{ $rule->name }}</span>
                            <span class="block truncate text-xs text-slate-500">{{ $rule->action->label() }} · {{ $rule->keywords_count }}</span>
                            <span class="mt-1 block"><x-admin.badge :color="$rule->is_active ? 'brand' : 'muted'" pill dot>{{ $rule->is_active ? __($t.'.status_active') : __($t.'.status_inactive') }}</x-admin.badge></span>
                        </a>
                        @include('livewire.admin.chatbot.rules.partials.actions', ['rule' => $rule])
                    </article>
                @endforeach
            </x-admin.mobile-list>

            <div class="mt-4">{{ $items->links() }}</div>
        @endif
    </div>
</div>
