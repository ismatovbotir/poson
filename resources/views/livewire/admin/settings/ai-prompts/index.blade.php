@php
    $t = 'admin.ai_prompts';
    $when = fn ($date) => $date ? \App\Support\DateFormat::iso($date->copy()->setTimezone(\App\Models\AiPromptOverride::TIMEZONE), 'D MMM YYYY, HH:mm') : null;
@endphp

{{-- Settings → AI prompts (plan 12): AI fields by screen, Default / Customized. --}}
<div>
    <x-admin.page-header :title="__($t.'.title')" :subtitle="__($t.'.subtitle')">
        <x-slot:breadcrumbs>{{ __('menu.groups.settings') }} <span class="text-slate-300">/</span> {{ __($t.'.title') }}</x-slot:breadcrumbs>
    </x-admin.page-header>

    <p class="mt-4 flex items-start gap-2 rounded-lg bg-slate-50 px-3 py-2.5 text-sm text-slate-600" role="note">
        <x-admin.icon name="info" size="size-4" class="mt-0.5 shrink-0 text-slate-400" />{{ __($t.'.fixed_rules') }}
    </p>

    <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-2 lg:gap-6">
        @foreach ($groups as $group => $fields)
            <x-admin.card :title="__($t.'.groups.'.$group)" :padding="false" data-prompt-group="{{ $group }}">
                <ul class="divide-y divide-slate-100">
                    @foreach ($fields as $field)
                        @php($override = $overrides[$field->key] ?? null)
                        <li wire:key="ai-prompt-{{ $field->key }}" class="flex items-center gap-3 px-4 py-3 sm:px-6" data-prompt-field="{{ $field->key }}">
                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold">{{ $field->label() }}</span>
                                    @if ($override)
                                        <x-admin.badge color="violet" data-prompt-customized>{{ __($t.'.customized') }}</x-admin.badge>
                                    @else
                                        <x-admin.badge color="muted" data-prompt-default>{{ __($t.'.default') }}</x-admin.badge>
                                    @endif
                                </p>
                                <p class="mt-0.5 font-mono text-xs text-slate-500">{{ $field->key }}</p>
                                @if ($override)
                                    <p class="mt-0.5 text-xs text-slate-500" data-prompt-editor>{{ __($t.'.customized_by', ['name' => $override['by'] ?? __($t.'.deleted_user'), 'date' => $when($override['at']) ?? __('admin.common.empty_value')]) }}</p>
                                @endif
                            </div>
                            <x-admin.button variant="secondary" size="sm" icon="edit" :href="route('admin.settings.ai-prompts.edit', $field->key)" wire:navigate>{{ __('admin.common.edit') }}</x-admin.button>
                        </li>
                    @endforeach
                </ul>
            </x-admin.card>
        @endforeach
    </div>
</div>
