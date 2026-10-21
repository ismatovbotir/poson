@php
    $t = 'admin.settings.integrations';
    // Connection test per secret (Payme / Click have none: webhook URLs instead).
    $tests = ['anthropic.api_key' => 'anthropic', 'openai.api_key' => 'openai', 'removebg.api_key' => 'removebg', 'telegram.bot_token' => 'telegram'];
    $icons = ['ai' => 'sparkles', 'telegram' => 'telegram', 'payme' => 'card', 'click' => 'card', 'removebg' => 'image'];
@endphp

{{-- Settings → Integrations (plan 04b T7). Secrets are write-only: only their status is rendered. --}}
<div>
    <x-admin.page-header :title="__($t.'.title')" :subtitle="__($t.'.subtitle')" />

    {{-- Password re-confirmation (15 min) --}}
    @if ($passwordConfirmed)
        <p class="mt-4 flex items-center gap-2 text-sm text-slate-500" data-password-confirmed>
            <x-admin.icon name="shield" size="size-4" class="text-brand-600" />{{ __($t.'.confirmed', ['minutes' => max(1, (int) ceil(\App\Support\Admin\PasswordConfirmation::secondsLeft() / 60))]) }}
        </p>
    @else
        <div class="mt-4 flex flex-col gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 sm:flex-row sm:items-center sm:justify-between" role="status" data-password-required>
            <p class="flex gap-2"><x-admin.icon name="lock" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.confirm_required', ['minutes' => intdiv(\App\Support\Admin\PasswordConfirmation::timeout(), 60)]) }}</p>
            <x-admin.button size="sm" icon="shield" wire:click="confirmPassword">{{ __($t.'.confirm_button') }}</x-admin.button>
        </div>
    @endif

    <nav class="scrollbar-none mt-4 flex gap-2 overflow-x-auto" aria-label="{{ __($t.'.sections_label') }}">
        @foreach (\App\Support\Integrations\IntegrationRegistry::GROUPS as $group)
            <a href="#section-{{ $group }}" class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-600 hover:bg-slate-50">
                <x-admin.icon :name="$icons[$group]" size="size-4" />{{ __($t.'.groups.'.$group) }}
            </a>
        @endforeach
    </nav>

    <fieldset class="mt-5 max-w-4xl space-y-4 pb-10 lg:space-y-6" @disabled(! $passwordConfirmed)>
        <legend class="sr-only">{{ __($t.'.title') }}</legend>

        @foreach ($sections as $group => $fields)
            @php
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

                        @if ($group === 'payme' && $paymeTestKeyMissing)
                            <p class="mt-4 flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="note" data-payme-test-key-warning>
                                <x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.payme.test_key_missing') }}
                            </p>
                        @endif

                        @if ($group === 'payme' && $paymeProd && ! $isProduction)
                            <p class="mt-4 flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="note" data-payme-warning>
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
        @endforeach
    </fieldset>

    {{-- Settings change history (all areas, never values) --}}
    <section class="max-w-4xl" aria-labelledby="audit-title" data-audit>
        <h2 id="audit-title" class="text-lg font-bold tracking-tight">{{ __($t.'.audit.title') }}</h2>
        <p class="mt-0.5 text-sm text-slate-500">{{ __($t.'.audit.subtitle', ['count' => \App\Livewire\Admin\Settings\Integrations::AUDIT_LIMIT]) }}</p>

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
                        <td class="px-4 py-3">{{ $audit->area->label() }}</td>
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
                        <p class="mt-1 text-xs text-slate-500">{{ $audit->area->label() }} · {{ $audit->user?->name ?? __('admin.common.empty_value') }} · {{ $audit->created_at->format('d.m.Y H:i') }} · {{ $audit->ip ?? __('admin.common.empty_value') }}</p>
                    </article>
                @endforeach
            </x-admin.mobile-list>

            {{ $audits->links() }}
        @endif
    </section>
</div>
