@php($t = 'admin.settings.indexing')

{{-- Settings → Indexing (plan 15 C). Admin only; saving needs a recent password confirmation and is audited. --}}
<form wire:submit="save" novalidate class="pb-28" data-indexing-settings>
    <x-admin.page-header :title="__($t.'.title')" :subtitle="__($t.'.subtitle')">
        <x-slot:breadcrumbs>{{ __('menu.groups.settings') }} <span class="text-slate-300">/</span> {{ __($t.'.title') }}</x-slot:breadcrumbs>
    </x-admin.page-header>

    <div class="mt-5 max-w-4xl space-y-4 lg:space-y-6">
        <x-admin.card :title="__($t.'.card_title')" :description="__($t.'.card_hint')">
            <x-slot:actions>
                <x-admin.badge :color="$isOpen ? 'brand' : 'amber'" dot data-indexing-state="{{ $isOpen ? 'open' : 'closed' }}">
                    {{ $isOpen ? __($t.'.state_open') : __($t.'.state_closed') }}
                </x-admin.badge>
            </x-slot:actions>

            @unless ($passwordConfirmed)
                <p class="mb-4 flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="note" data-password-required>
                    <x-admin.icon name="lock" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.password_hint') }}
                </p>
            @endunless

            <x-admin.toggle wire:model="open" :label="__($t.'.label')" :description="__($t.'.help')" />

            <ul class="mt-4 list-disc space-y-1 pl-5 text-sm text-slate-600">
                <li>{{ __($t.'.closed_effect') }}</li>
                <li>{{ __($t.'.open_effect') }}</li>
                <li>{{ __($t.'.analytics_note') }}</li>
            </ul>
        </x-admin.card>

        <x-admin.card :title="__($t.'.audit_title')">
            @if ($audits->isEmpty())
                <p class="text-sm text-slate-500">{{ __($t.'.audit_empty') }}</p>
            @else
                <ul class="divide-y divide-slate-100 text-sm">
                    @foreach ($audits as $audit)
                        <li wire:key="indexing-audit-{{ $audit->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2" data-audit-row>
                            <x-admin.badge :color="$audit->action === \App\Enums\AuditAction::Opened ? 'brand' : 'amber'">{{ $audit->action->label() }}</x-admin.badge>
                            <span class="text-xs text-slate-500">{{ $audit->user?->name ?? __('admin.common.empty_value') }} · {{ $audit->created_at->format('d.m.Y H:i') }} · <span class="font-mono">{{ $audit->ip ?? __('admin.common.empty_value') }}</span></span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-admin.card>
    </div>

    <x-admin.sticky-save-bar />
</form>
