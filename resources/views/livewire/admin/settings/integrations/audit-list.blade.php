@php($t = 'admin.settings.integrations')

@if ($audits->isEmpty())
    <x-admin.empty-state class="mt-4" icon="clock" :title="__($t.'.audit.empty')" :body="__($t.'.audit.empty_body')" />
@else
    <x-admin.table class="mt-4">
        <x-slot:caption>{{ __($t.'.audit.title') }}</x-slot:caption>
        <x-slot:head>
            <th class="px-4 py-3">{{ __($t.'.audit.when') }}</th>
            <th class="px-4 py-3">{{ __($t.'.audit.user') }}</th>
            <th class="px-4 py-3">{{ __($t.'.audit.area') }}</th>
            <th class="px-4 py-3">{{ __($t.'.audit.key') }}</th>
            <th class="px-4 py-3">{{ __($t.'.audit.action') }}</th>
            <th class="px-4 py-3">{{ __($t.'.audit.ip') }}</th>
        </x-slot:head>
        @foreach ($audits as $audit)
            <tr wire:key="audit-{{ $audit->id }}" data-audit-row>
                <td class="whitespace-nowrap px-4 py-3 text-slate-500">{{ $audit->created_at->format('d.m.Y H:i') }}</td>
                <td class="px-4 py-3">{{ $audit->user?->name ?? __('admin.common.empty_value') }}</td>
                <td class="px-4 py-3">{{ $audit->area?->label() ?? $audit->getRawOriginal('area') }}</td>
                <td class="px-4 py-3 font-mono text-xs">{{ $audit->key }}</td>
                <td class="px-4 py-3"><x-admin.badge :color="$audit->action === \App\Enums\AuditAction::Cleared ? 'amber' : 'slate'">{{ $audit->action->label() }}</x-admin.badge></td>
                <td class="px-4 py-3 font-mono text-xs text-slate-500">{{ $audit->ip ?? __('admin.common.empty_value') }}</td>
            </tr>
        @endforeach
    </x-admin.table>

    <x-admin.mobile-list class="mt-4">
        @foreach ($audits as $audit)
            <article wire:key="audit-m-{{ $audit->id }}" class="rounded-2xl border border-slate-200 bg-white p-3.5 text-sm">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-mono text-xs">{{ $audit->key }}</span>
                    <x-admin.badge :color="$audit->action === \App\Enums\AuditAction::Cleared ? 'amber' : 'slate'">{{ $audit->action->label() }}</x-admin.badge>
                </div>
                <p class="mt-1 text-xs text-slate-500">{{ $audit->area?->label() ?? $audit->getRawOriginal('area') }} · {{ $audit->user?->name ?? __('admin.common.empty_value') }} · {{ $audit->created_at->format('d.m.Y H:i') }} · {{ $audit->ip ?? __('admin.common.empty_value') }}</p>
            </article>
        @endforeach
    </x-admin.mobile-list>

    {{ $audits->links() }}
@endif
