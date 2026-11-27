@php($t = 'office.review')

{{-- Virtual office → Education → Review (plan 46 §2.2). Admin only. Counts and sums only: never an average. All values escaped. --}}
<div data-office-education-review>
    <x-admin.page-header :title="__($t.'.title')" :subtitle="__($t.'.subtitle')">
        <x-slot:breadcrumbs>{{ __('menu.groups.office') }} <span class="text-slate-300">/</span> <a href="{{ route('admin.office.education') }}" wire:navigate class="hover:underline">{{ __('office.education.title') }}</a> <span class="text-slate-300">/</span> {{ __($t.'.title') }}</x-slot:breadcrumbs>
    </x-admin.page-header>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <div class="flex flex-wrap rounded-lg border border-slate-200 bg-white p-1" role="tablist" data-review-tabs>
            @foreach (['feedback', 'attention', 'metrics'] as $name)
                <button type="button" role="tab" wire:click="setTab('{{ $name }}')" aria-selected="{{ $tab === $name ? 'true' : 'false' }}" data-tab="{{ $name }}" @class(['h-8 rounded-md px-3 text-sm font-semibold', 'bg-slate-900 text-white' => $tab === $name, 'text-slate-600 hover:text-slate-900' => $tab !== $name])>{{ __($t.'.tab_'.$name) }}</button>
            @endforeach
        </div>
        <a href="{{ route('admin.chatbot.unmatched') }}" wire:navigate class="text-sm font-semibold text-brand-700 hover:underline" data-unmatched-link>{{ __($t.'.unmatched') }}</a>
    </div>

    @if ($tab !== 'attention')
        <div class="mt-4 grid gap-3 sm:grid-cols-4" data-review-filters>
            <x-admin.select wire:model.live="role" :label="__($t.'.role')" :placeholder="__($t.'.all')" :options="collect($roles)->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" />
            <x-admin.select wire:model.live="outcome" :label="__($t.'.outcome')" :placeholder="__($t.'.edited_rejected')" :options="collect($outcomes)->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" />
            <x-admin.select wire:model.live="reason" :label="__($t.'.reason')" :placeholder="__($t.'.all')" :options="collect($reasons)->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" />
            <x-admin.select wire:model.live="period" :label="__($t.'.period')" :options="['7' => __($t.'.days', ['n' => 7]), '30' => __($t.'.days', ['n' => 30]), '90' => __($t.'.days', ['n' => 90]), 'all' => __($t.'.all_time')]" />
        </div>
    @endif

    @error('feedback')<p class="mt-4 rounded-lg bg-red-50 px-3 py-2.5 text-sm text-danger" role="alert">{{ $message }}</p>@enderror

    @if ($tab === 'feedback')
        <x-admin.card class="mt-4" :title="__($t.'.tab_feedback')" :description="__($t.'.feedback_hint', ['max' => \App\Livewire\Admin\Office\EducationReview::LIMIT])">
            @forelse ($rows as $row)
                <div wire:key="fb-{{ $row->id }}" class="border-b border-slate-100 py-3 text-sm last:border-0" data-feedback="{{ $row->id }}">
                    <div class="flex flex-wrap items-center gap-2">
                        <x-admin.badge color="violet">{{ $row->role->label() }}</x-admin.badge>
                        <x-admin.badge :color="$row->outcome === \App\Enums\DraftOutcome::Rejected ? 'red' : 'slate'">{{ $row->outcome->label() }}</x-admin.badge>
                        @if ($row->reason)<span class="text-slate-600">{{ $row->reason->label() }}</span>@endif
                        <span class="text-xs text-slate-500">v{{ $row->agentVersion?->version ?? '?' }} · {{ $row->created_at?->copy()->setTimezone(config('app.timezone'))->format('d.m.Y H:i') }} · {{ $row->user?->name }}</span>
                        @if ($row->business_chat_id)<a href="{{ route('admin.office.chats.show', $row->business_chat_id) }}" wire:navigate class="text-xs font-semibold text-brand-700 hover:underline">{{ __($t.'.open_chat') }}</a>@endif
                        @if ($row->telegram_group_id)<a href="{{ route('admin.office.groups.show', $row->telegram_group_id) }}" wire:navigate class="text-xs font-semibold text-brand-700 hover:underline">{{ __($t.'.open_group') }}</a>@endif
                        @if ($row->promoted_version_id)<x-admin.badge color="brand" pill>{{ __($t.'.promoted_badge') }}</x-admin.badge>@endif
                    </div>
                    <div class="mt-1.5 flex flex-wrap gap-3 text-xs font-semibold">
                        <button type="button" wire:click="open({{ $row->id }})" class="text-slate-600 hover:underline" data-action="open">{{ __($t.'.show_texts') }}</button>
                        @if (! $row->promoted_version_id)
                            <button type="button" wire:click="startPromote({{ $row->id }})" class="text-brand-700 hover:underline" data-action="promote">{{ __($t.'.promote') }}</button>
                        @endif
                    </div>
                    @if ($open && $open->id === $row->id)
                        <dl class="mt-2 space-y-1.5 rounded-lg bg-slate-50 p-3" data-feedback-texts>
                            @foreach (['draft_text' => $open->draft_text, 'final_text' => $open->final_text, 'note' => $open->note] as $field => $value)
                                <div><dt class="text-xs text-slate-500">{{ __($t.'.'.$field) }}</dt><dd class="whitespace-pre-line break-words">{{ \App\Casts\EncryptedText::isReadable($value) ? $value : __('admin.common.empty_value') }}</dd></div>
                            @endforeach
                            <x-admin.button variant="ghost" size="sm" wire:click="close">{{ __('admin.a11y.close') }}</x-admin.button>
                        </dl>
                    @endif
                </div>
            @empty
                <p class="text-sm text-slate-500" data-empty>{{ __($t.'.empty') }}</p>
            @endforelse
        </x-admin.card>

        @if ($promoting)
            <x-admin.card class="mt-4" :title="__($t.'.promote_title')" :description="__($t.'.promote_hint')" data-promote-form>
                <form wire:submit="promote" class="space-y-3" novalidate>
                    <p class="flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2.5 text-sm text-amber-800" role="note"><x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __('office.education.examples_warning') }}</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <x-admin.select wire:model="kind" :label="__($t.'.kind')" :options="['good' => __('office.education.kind_good'), 'bad' => __('office.education.kind_bad')]" />
                        <x-admin.select wire:model="language" :label="__('office.education.example_language')" :options="['uz' => 'uz', 'ru' => 'ru', 'en' => 'en']" />
                    </div>
                    <x-admin.textarea wire:model="client" :label="__('office.education.example_client')" :hint="__($t.'.client_hint')" rows="2" maxlength="{{ config('office.education.example_chars') }}" />
                    <x-admin.textarea wire:model="reply" :label="__('office.education.example_reply')" rows="4" maxlength="{{ config('office.education.example_chars') }}" />
                    <x-admin.input wire:model="note" :label="__('office.education.example_note')" maxlength="{{ config('office.education.guidance.example_note_chars') }}" />
                    <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="anonymised" class="mt-0.5 size-4 shrink-0 rounded accent-brand-600" data-anonymised>{{ __($t.'.anonymised') }}</label>
                    <x-admin.input wire:model="password" type="password" autocomplete="current-password" :label="__($t.'.password')" />
                    @error('guard')<p class="text-sm text-danger" role="alert" data-guard-error>{{ $message }}</p>@enderror
                    @error('kind')<p class="text-sm text-danger" role="alert">{{ $message }}</p>@enderror
                    <div class="flex flex-wrap gap-2">
                        <x-admin.button type="submit" icon="check" loading="promote">{{ __($t.'.promote_submit') }}</x-admin.button>
                        <x-admin.button variant="ghost" wire:click="close">{{ __('admin.a11y.close') }}</x-admin.button>
                    </div>
                </form>
            </x-admin.card>
        @endif
    @endif

    @if ($tab === 'attention')
        <x-admin.card class="mt-4" :title="__($t.'.tab_attention')" :description="__($t.'.attention_hint')">
            @forelse ($attention['chats'] ?? [] as $item)
                <p wire:key="a-chat-{{ $item->id }}" class="border-b border-slate-100 py-2 text-sm last:border-0">
                    <a href="{{ route('admin.office.chats.show', $item->business_chat_id) }}" wire:navigate class="font-semibold text-brand-700 hover:underline">{{ __($t.'.open_chat') }} #{{ $item->business_chat_id }}</a>
                    · {{ $item->role?->label() }}
                    @if ($item->needs_human) · {{ __('office.show.needs_human') }}@endif
                    @if ($item->draft_hold) · {{ $item->draft_hold->label() }}@endif
                    @if ($item->draft_blocked) · {{ __('office.show.draft_blocked') }}@endif
                </p>
            @empty
            @endforelse
            @foreach ($attention['threads'] ?? [] as $item)
                <p wire:key="a-thread-{{ $item->id }}" class="border-b border-slate-100 py-2 text-sm last:border-0">
                    <a href="{{ route('admin.office.groups.show', $item->telegram_group_id) }}" wire:navigate class="font-semibold text-brand-700 hover:underline">{{ __($t.'.open_group') }} #{{ $item->telegram_group_id }}</a>
                    · {{ $item->role?->label() }}
                    @if ($item->needs_human) · {{ __('office.show.needs_human') }}@endif
                    @if ($item->draft_hold) · {{ $item->draft_hold->label() }}@endif
                    @if ($item->draft_blocked) · {{ __('office.show.draft_blocked') }}@endif
                </p>
            @endforeach
            @if (count($attention['chats'] ?? []) + count($attention['threads'] ?? []) === 0)
                <p class="text-sm text-slate-500" data-empty>{{ __($t.'.empty_attention') }}</p>
            @endif
        </x-admin.card>
    @endif

    @if ($tab === 'metrics')
        <div class="mt-4 grid gap-4 lg:grid-cols-2" data-metrics>
            <x-admin.card :title="__($t.'.by_outcome')">
                <dl class="space-y-1 text-sm">
                    @foreach ($outcomes as $case)<div class="flex justify-between"><dt>{{ $case->label() }}</dt><dd class="font-semibold" data-count="outcome-{{ $case->value }}">{{ $metrics['outcomes'][$case->value] ?? 0 }}</dd></div>@endforeach
                </dl>
            </x-admin.card>
            <x-admin.card :title="__($t.'.by_reason')">
                <dl class="space-y-1 text-sm">
                    @foreach ($reasons as $case)<div class="flex justify-between"><dt>{{ $case->label() }}</dt><dd class="font-semibold" data-count="reason-{{ $case->value }}">{{ $metrics['reasons'][$case->value] ?? 0 }}</dd></div>@endforeach
                </dl>
            </x-admin.card>
            <x-admin.card class="lg:col-span-2" :title="__($t.'.by_version')" :description="__($t.'.metrics_hint')">
                <div class="overflow-x-auto"><table class="w-full text-left text-sm">
                    <thead><tr class="text-left text-xs text-slate-500">
                        @foreach (['role', 'version', 'analyses', 'used', 'edited', 'rejected', 'blocked', 'held', 'needs_human', 'tokens_in', 'tokens_out'] as $column)<th class="px-3 py-2 font-semibold">{{ __($t.'.col_'.$column) }}</th>@endforeach
                    </tr></thead>
                    <tbody>
                        @forelse ($metrics['versions'] ?? [] as $row)
                            <tr class="border-t border-slate-100" data-version-row>
                                <td class="px-3 py-2">{{ $row['role']?->label() }}</td>
                                <td class="px-3 py-2">v{{ $row['version'] }}</td>
                                <td class="px-3 py-2">{{ $row['analyses'] }}</td><td class="px-3 py-2">{{ $row['used'] }}</td><td class="px-3 py-2">{{ $row['edited'] }}</td><td class="px-3 py-2">{{ $row['rejected'] }}</td>
                                <td class="px-3 py-2">{{ $row['blocked'] }}</td><td class="px-3 py-2">{{ $row['held'] }}</td><td class="px-3 py-2">{{ $row['needs_human'] }}</td>
                                <td class="px-3 py-2">{{ $row['input_tokens'] }}</td><td class="px-3 py-2">{{ $row['output_tokens'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="11" class="px-3 py-4 text-sm text-slate-500" data-empty>{{ __($t.'.empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </x-admin.card>
        </div>
    @endif
</div>
