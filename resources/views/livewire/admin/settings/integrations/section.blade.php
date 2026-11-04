@php
    $t = 'admin.settings.integrations';
    // Connection test per secret (Payme / Click sections are hidden, plan 23).
    $tests = ['telegram.bot_token' => 'telegram', 'indexnow.key' => 'indexnow'];
    $secrets = array_filter($fields, fn (array $state): bool => $state['field']->secret);
    $settings = array_filter($fields, fn (array $state): bool => ! $state['field']->secret);
    $editable = array_filter($settings, fn (array $state): bool => ! $state['locked']);
@endphp

<x-admin.card :title="__($t.'.groups.'.$group)" :description="__($t.'.descriptions.'.$group)" id="section-{{ $group }}" class="scroll-mt-24" data-section="{{ $group }}" wire:key="section-{{ $group }}">
    <div class="space-y-3">
        @foreach ($secrets as $key => $state)
            @include('livewire.admin.settings.integrations.secret', ['field' => $state['field'], 'state' => $state, 'test' => $tests[$key] ?? null])
        @endforeach
    </div>

    @if ($settings !== [])
        <form wire:submit="save('{{ $group }}')" novalidate @class(['mt-5 border-t border-slate-100 pt-5' => $secrets !== []])>
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ($settings as $key => $state)
                    @include('livewire.admin.settings.integrations.setting', ['field' => $state['field'], 'state' => $state])
                @endforeach
            </div>

            @if (in_array($group, $testKeyMissing ?? [], true))
                <p class="mt-4 flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="note" data-{{ $group }}-test-key-warning>
                    <x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.'.$group.'.test_key_missing') }}
                </p>
            @endif

            @if (in_array($group, $prodGroups ?? [], true) && ! $isProduction)
                <p class="mt-4 flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="note" data-{{ $group }}-warning>
                    <x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.payme.not_production') }}
                </p>
            @endif

            @if ($group === 'telegram')
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach (\App\Actions\Settings\TestIntegrationConnection::CHATS as $chat)
                        <x-admin.button variant="soft" size="sm" icon="message" wire:click="test('telegram_chat', '{{ $chat }}')" loading="test" :disabled="! $fields['telegram.bot_token']['configured'] || ! $fields['telegram.chats.'.$chat]['configured']" data-test="telegram_chat_{{ $chat }}">
                            {{ __($t.'.telegram.send_test', ['chat' => __($t.'.fields.telegram_chats_'.$chat)]) }}
                        </x-admin.button>
                    @endforeach
                </div>
                @foreach (\App\Actions\Settings\TestIntegrationConnection::CHATS as $chat)
                    @isset($testResults['telegram_chats_'.$chat])
                        @include('livewire.admin.settings.integrations.test-result', ['result' => $testResults['telegram_chats_'.$chat]])
                    @endisset
                @endforeach
            @endif

            @if ($editable !== [])
                <div class="mt-5 flex justify-end">
                    <x-admin.button type="submit" icon="check" loading="save">{{ __('admin.common.save') }}</x-admin.button>
                </div>
            @endif
        </form>
    @endif

    @if ($group === 'indexnow')
        @include('livewire.admin.settings.integrations.indexnow', ['indexnow' => $indexnow])
    @endif

    @if ($group === 'uzqr')
        @include('livewire.admin.settings.integrations.uzqr', ['uzqr' => $uzqr, 'fields' => $fields])
    @endif

    @if (in_array($group, ['payme', 'click'], true))
        <div class="mt-5 border-t border-slate-100 pt-5" data-webhooks="{{ $group }}">
            <p class="text-sm font-semibold">{{ __($t.'.webhooks.title') }}</p>
            <p class="mt-0.5 text-xs text-slate-500">{{ __($t.'.webhooks.hint_'.$group) }}</p>
            @unless ($webhooksPublic)
                <p class="mt-3 flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="note" data-webhooks-not-public>
                    <x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.webhooks.not_public', ['url' => config('app.url')]) }}
                </p>
            @endunless
            <div class="mt-3 space-y-2">
                @foreach ($group === 'payme' ? ['payme'] : ['click_prepare', 'click_complete'] as $hook)
                    <div x-data="{ copied: false }" class="flex items-center gap-2">
                        <span class="w-24 shrink-0 text-xs text-slate-500">{{ __($t.'.webhooks.'.$hook) }}</span>
                        <input type="text" readonly value="{{ $webhookUrls[$hook] }}" class="h-9 min-w-0 flex-1 rounded-lg border border-slate-200 bg-slate-50 px-3 font-mono text-xs text-slate-700" aria-label="{{ __($t.'.webhooks.'.$hook) }}" x-on:focus="$el.select()">
                        <button type="button" class="grid size-9 shrink-0 place-items-center rounded-lg text-slate-500 hover:bg-slate-100" x-on:click="navigator.clipboard?.writeText(@js($webhookUrls[$hook])); copied = true; setTimeout(() => copied = false, 1500)" aria-label="{{ __($t.'.webhooks.copy') }}">
                            <x-admin.icon name="copy" size="size-4" x-show="! copied" />
                            <x-admin.icon name="check" size="size-4" class="text-brand-600" x-show="copied" x-cloak />
                        </button>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</x-admin.card>
