@php
    /** @var \App\Support\Integrations\IntegrationField $field */
    $t = 'admin.settings.integrations';
    $key = $field->key;
    $id = $field->langKey();
@endphp

{{-- One secret: status only (never the value), write-only Replace input, Clear, Test. --}}
<div class="rounded-xl border border-slate-200 p-4" wire:key="secret-{{ $id }}" data-secret="{{ $key }}">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div class="min-w-0">
            <p class="text-sm font-semibold">{{ $field->label() }}</p>
            <p class="mt-0.5 text-xs text-slate-500">{{ __($t.'.hints.'.$id) }}</p>
        </div>

        @if ($state['locked'])
            <x-admin.badge color="violet" data-status="env"><x-admin.icon name="lock" size="size-3.5" />{{ __($t.'.status.locked') }}</x-admin.badge>
        @elseif ($state['unreadable'])
            <x-admin.badge color="red" data-status="unreadable"><x-admin.icon name="alert" size="size-3.5" />{{ __($t.'.status.unreadable') }}</x-admin.badge>
        @elseif ($state['source'] === \App\Enums\IntegrationSource::Db)
            <x-admin.badge color="brand" dot data-status="configured">{{ __($t.'.status.configured') }} <span class="font-mono">••••{{ $state['hint'] }}</span></x-admin.badge>
        @else
            <x-admin.badge color="muted" data-status="none">{{ __($t.'.status.not_set') }}</x-admin.badge>
        @endif
    </div>

    @if ($state['locked'])
        <p class="mt-3 text-xs text-slate-500">{{ __($t.'.locked_hint', ['env' => $field->env]) }}</p>
    @else
        @if ($state['unreadable'])
            <p class="mt-3 text-xs text-danger">{{ __($t.'.unreadable_hint') }}</p>
        @endif

        @unless ($field->generated)
            <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-start">
                <x-admin.input
                    wire:model="secretInputs.{{ $key }}"
                    type="password"
                    autocomplete="new-password"
                    autocapitalize="off"
                    spellcheck="false"
                    data-1p-ignore
                    data-lpignore="true"
                    class="flex-1"
                    maxlength="300"
                    :placeholder="$state['source'] === \App\Enums\IntegrationSource::Db ? __($t.'.replace_placeholder') : __($t.'.new_placeholder')"
                    :aria-label="__($t.'.replace_label', ['name' => $field->label()])"
                />
                <x-admin.button variant="secondary" icon="check" wire:click="replaceSecret('{{ $key }}')" loading="replaceSecret">
                    {{ $state['source'] === \App\Enums\IntegrationSource::Db ? __($t.'.replace') : __($t.'.set') }}
                </x-admin.button>
            </div>
        @endunless
    @endif

    <div class="mt-3 flex flex-wrap items-center gap-2">
        @if ($test)
            <x-admin.button variant="soft" size="sm" icon="plug" wire:click="test('{{ $test }}')" loading="test" :disabled="! $state['configured']" data-test="{{ $test }}">{{ __($t.'.test.button') }}</x-admin.button>
        @endif

        @if ($field->generated && ! $state['locked'])
            <x-admin.button
                variant="soft"
                size="sm"
                icon="restore"
                x-on:click="$dispatch('confirm', {
                    title: {{ \Illuminate\Support\Js::from(__($t.'.telegram.generate_title')) }},
                    body: {{ \Illuminate\Support\Js::from(__($t.'.telegram.generate_body')) }},
                    label: {{ \Illuminate\Support\Js::from(__($t.'.telegram.generate')) }},
                    component: $wire.$id, method: 'generateWebhookSecret',
                })"
            >{{ __($t.'.telegram.generate') }}</x-admin.button>
        @endif

        @if (! $state['locked'] && ($state['source'] === \App\Enums\IntegrationSource::Db || $state['unreadable']))
            <x-admin.button
                variant="ghost"
                size="sm"
                icon="trash"
                class="text-danger hover:bg-red-50"
                x-on:click="$dispatch('confirm', {
                    title: {{ \Illuminate\Support\Js::from(__($t.'.clear_title', ['name' => $field->label()])) }},
                    body: {{ \Illuminate\Support\Js::from(__($t.'.clear_body')) }},
                    label: {{ \Illuminate\Support\Js::from(__($t.'.clear')) }},
                    component: $wire.$id, method: 'clearSecret', params: [{{ \Illuminate\Support\Js::from($key) }}],
                })"
            >{{ __($t.'.clear') }}</x-admin.button>
        @endif
    </div>

    @if ($test && isset($testResults[$id]))
        @include('livewire.admin.settings.integrations.test-result', ['result' => $testResults[$id]])
    @endif
</div>
