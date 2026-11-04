@php($t = 'telegram_inbox')
@php($name = \App\Support\Telegram\InboxMessage::displayName($conversation))
@php($tz = config('app.timezone'))

{{-- Telegram → Chats → conversation (plan 14 v2 §9, plan 21 stream A). Polls every 10 s while visible. --}}
<div @if ($polling) wire:poll.10s.visible="refreshThread" @endif data-telegram-chat="{{ $conversation->id }}">
    <x-admin.page-header :title="$name !== '' ? $name : __($t.'.unnamed')" :subtitle="__($t.'.show.subtitle', ['id' => $conversation->id])">
        <x-slot:breadcrumbs>
            {{ __('menu.groups.telegram') }} <span class="text-slate-300">/</span>
            <a href="{{ route('admin.telegram.chats') }}" wire:navigate class="hover:text-brand-700">{{ __($t.'.title') }}</a>
            <span class="text-slate-300">/</span> #{{ $conversation->id }}
        </x-slot:breadcrumbs>
        <x-slot:actions>
            @unless ($polling)
                <x-admin.button variant="secondary" icon="restore" wire:click="refreshThread" loading="refreshThread" data-refresh>{{ __($t.'.show.refresh') }}</x-admin.button>
            @endunless
            <x-admin.button :href="route('admin.telegram.chats')" wire:navigate variant="secondary" icon="chevron-left">{{ __($t.'.show.back') }}</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mt-5 grid gap-4 lg:grid-cols-3 lg:gap-6">
        <div class="space-y-3 lg:col-span-2">
            @if ($conversation->isBanned())
                <p class="flex gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-danger" role="alert" data-banner="banned"><x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.banners.banned') }}</p>
            @endif
            @if ($conversation->blocked_at)
                <p class="flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="note" data-banner="blocked"><x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.banners.blocked') }}</p>
            @endif
            @if ($conversation->account_changed)
                <p class="flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="note" data-banner="account-changed"><x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.banners.account_changed') }}</p>
            @endif
            @if ($conversation->customer_id !== null && ! $conversation->isLinkVerified())
                <p class="flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="note" data-banner="unverified"><x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.banners.unverified') }}</p>
            @endif
            @if ($forwardNotConfigured)
                <p class="flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="note" data-banner="forward-not-configured"><x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.banners.forward_not_configured') }}</p>
            @endif
            @if ($replyRefusal === 'bot_off')
                <p class="flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="note" data-banner="bot-off"><x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.banners.bot_off') }}</p>
            @endif
            @unless ($conversation->isRegistered())
                <p class="flex gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700" role="note" data-banner="unregistered"><x-admin.icon name="info" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.banners.unregistered') }}</p>
            @endunless

            <x-admin.card :padding="false">
                <div class="space-y-3 p-4 sm:p-5" data-thread>
                    @if ($hasOlder)
                        <div class="text-center">
                            <x-admin.button variant="secondary" size="sm" wire:click="loadOlder" loading="loadOlder" data-load-older>{{ __($t.'.show.load_older') }}</x-admin.button>
                        </div>
                    @endif

                    @if ($olderHidden)
                        <p class="text-center text-xs text-slate-500" data-older-hidden>{{ __($t.'.show.older_hidden', ['count' => \App\Livewire\Admin\Telegram\Chats\Show::MAX_MESSAGES]) }}</p>
                    @endif

                    @forelse ($messages as $message)
                        @php($inbound = $message->direction === \App\Enums\TelegramDirection::In)
                        @php($stuckRow = $message->isStuck())
                        <div wire:key="message-{{ $message->id }}" @class(['flex', 'justify-start' => $inbound, 'justify-end' => ! $inbound]) data-message="{{ $message->id }}">
                            <div @class([
                                'max-w-[85%] rounded-2xl px-3.5 py-2.5 text-sm',
                                'bg-slate-100 text-slate-900' => $inbound,
                                'bg-brand-50 text-slate-900' => ! $inbound,
                                'ring-2 ring-amber-400' => $stuckRow,
                            ]) @if ($stuckRow) data-stuck @endif>
                                @if ($message->kind !== \App\Enums\TelegramMessageKind::Text)
                                    <p class="flex flex-wrap items-center gap-x-2 text-xs font-semibold text-slate-600" data-media>
                                        <x-admin.icon name="image" size="size-4" />
                                        <span>{{ $message->kind->label() }}</span>
                                        @if ($message->media['file_name'] ?? null)<span class="font-normal">{{ $message->media['file_name'] }}</span>@endif
                                        @if ($message->media['mime_type'] ?? null)<span class="font-normal text-slate-500">{{ $message->media['mime_type'] }}</span>@endif
                                        @if ($message->media['file_size'] ?? null)<span class="font-normal text-slate-500">{{ \Illuminate\Support\Number::fileSize((int) $message->media['file_size']) }}</span>@endif
                                        @if ($message->media['duration'] ?? null)<span class="font-normal text-slate-500">{{ gmdate('i:s', (int) $message->media['duration']) }}</span>@endif
                                    </p>
                                @endif
                                @if (filled($message->text))
                                    <p class="break-words">{!! nl2br(e(preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $message->text) ?? '')) !!}</p>
                                @elseif ($message->kind === \App\Enums\TelegramMessageKind::Text)
                                    <p class="text-slate-400">{{ __('admin.common.empty_value') }}</p>
                                @endif
                                <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-slate-500">
                                    <span>{{ $inbound ? __($t.'.show.from_customer') : ($message->user?->name ?? __($t.'.show.from_bot')) }}</span>
                                    <span>{{ \App\Support\DateFormat::iso(($message->sent_at ?? $message->created_at)->copy()->setTimezone($tz), 'D MMM, HH:mm') }}</span>
                                    @unless ($inbound)
                                        <x-admin.badge :color="$message->status === \App\Enums\TelegramMessageStatus::Failed ? 'red' : 'muted'">{{ $message->status->label() }}@if ($message->error_code) · {{ $message->error_code }}@endif</x-admin.badge>
                                    @endunless
                                    @if ($replyRefusal === null && $message->isResendable())
                                        @can('resend', [$conversation, $message])
                                            <button type="button" wire:click="resend({{ $message->id }})" @if ($message->status === \App\Enums\TelegramMessageStatus::Sending) wire:confirm="{{ __($t.'.resend.duplicate_warning') }}" @endif wire:loading.attr="disabled" wire:target="resend({{ $message->id }})" class="font-semibold text-brand-700 hover:underline" data-resend="{{ $message->id }}">{{ $message->status === \App\Enums\TelegramMessageStatus::Sending ? __($t.'.resend.resend') : __($t.'.resend.retry') }}</button>
                                        @else
                                            <span class="text-slate-600" data-resend-hint="{{ $message->id }}">{{ __($t.'.resend.only_author', ['name' => $message->user?->name ?? __($t.'.history.someone')]) }}</span>
                                        @endcan
                                    @endif
                                    @if ($stuckRow)
                                        <x-admin.badge color="amber">{{ __($t.'.badges.stuck') }}</x-admin.badge>
                                    @endif
                                </p>
                                @if ($message->forward_status !== null)
                                    <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px]" data-forward-line="{{ $message->forward_status->value }}">
                                        <span @class(['font-semibold', 'text-danger' => $message->forward_status === \App\Enums\TelegramForwardStatus::Failed, 'text-slate-500' => $message->forward_status !== \App\Enums\TelegramForwardStatus::Failed])>{{ __($t.'.show.forward') }}: {{ __($t.'.forward_lines.'.$message->forward_status->value) }}@if ($message->forward_error_code) · {{ $message->forward_error_code }}@endif</span>
                                        @if (! in_array($replyRefusal, ['banned', 'bot_off'], true) && $message->isForwardRetryable())
                                            <button type="button" wire:click="retryForward({{ $message->id }})" wire:loading.attr="disabled" wire:target="retryForward({{ $message->id }})" class="font-semibold text-brand-700 hover:underline" data-retry-forward="{{ $message->id }}">{{ __($t.'.forward_retry') }}</button>
                                        @endif
                                    </p>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="py-8 text-center text-sm text-slate-500" data-empty>{{ __($t.'.show.no_messages') }}</p>
                    @endforelse
                </div>
            </x-admin.card>

            @can('reply', $conversation)
                <x-admin.card>
                    {{-- One token per draft: any edit makes a new one, so a repeated Ctrl+Enter is ignored on the server. --}}
                    <form
                        x-data="{ token: '', renew() { this.token = Date.now() + '-' + Math.random().toString(36).slice(2) }, send() { this.$wire.sendReply(this.token) } }"
                        x-init="renew()"
                        x-on:submit.prevent="send()"
                        class="space-y-3"
                        data-reply-box
                    >
                        <x-admin.textarea
                            wire:model="reply"
                            x-on:input="renew()"
                            x-on:change="renew()"
                            x-on:paste="renew()"
                            x-on:keydown.ctrl.enter.prevent.throttle.1500ms="send()"
                            x-on:keydown.cmd.enter.prevent.throttle.1500ms="send()"
                            rows="3"
                            :label="__($t.'.reply.label')"
                            :placeholder="__($t.'.reply.placeholder')"
                            :hint="__($t.'.reply.hint', ['max' => \App\Actions\Telegram\SendTelegramReply::MAX_LENGTH])"
                            maxlength="{{ \App\Actions\Telegram\SendTelegramReply::MAX_LENGTH }}"
                            :disabled="$replyRefusal !== null"
                        />
                        <div class="flex justify-end">
                            <x-admin.button type="submit" icon="telegram" loading="sendReply" :disabled="$replyRefusal !== null" data-reply-send>{{ __($t.'.reply.send') }}</x-admin.button>
                        </div>
                    </form>
                </x-admin.card>
            @endcan
        </div>

        <aside class="space-y-4">
            <x-admin.card :title="__($t.'.panel.title')">
                <dl class="space-y-3 text-sm">
                    @foreach ([
                        'name' => $name !== '' ? $name : null,
                        'username' => $conversation->username ? '@'.$conversation->username : null,
                        'phone' => $conversation->phone ? \App\Support\Phone::display($conversation->phone) : null,
                        'company' => $conversation->declared_company,
                        'inn' => $conversation->declared_inn,
                        'locale' => $conversation->locale ? strtoupper($conversation->locale) : null,
                    ] as $field => $value)
                        <div>
                            <dt class="text-slate-500">{{ __($t.'.panel.'.$field) }}</dt>
                            <dd class="mt-0.5 flex flex-wrap items-center gap-2 break-words font-medium">
                                {{ $value ?? __('admin.common.empty_value') }}
                                @if ($value !== null && in_array($field, ['company', 'inn'], true))
                                    <x-admin.badge color="outline" data-unproven>{{ __($t.'.panel.unproven') }}</x-admin.badge>
                                @endif
                            </dd>
                        </div>
                    @endforeach
                    <div>
                        <dt class="text-slate-500">{{ __($t.'.panel.status') }}</dt>
                        <dd class="mt-0.5"><x-admin.badge :color="$conversation->conversation_status === \App\Enums\ConversationStatus::Open ? 'sky' : 'muted'">{{ $conversation->conversation_status->label() }}</x-admin.badge></dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">{{ __($t.'.panel.manager') }}</dt>
                        <dd class="mt-0.5 flex flex-wrap items-center gap-2 font-medium">
                            {{ $conversation->manager?->name ?? __($t.'.unassigned') }}
                            @if ($conversation->manager && ! $conversation->manager->is_active)
                                @can('telegram.inbox-all')
                                    <x-admin.badge color="amber" data-inactive-manager>{{ __($t.'.badges.inactive_manager') }}</x-admin.badge>
                                @endcan
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">{{ __($t.'.panel.registered_at') }}</dt>
                        <dd class="mt-0.5">{{ $conversation->registered_at ? \App\Support\DateFormat::iso($conversation->registered_at->copy()->setTimezone($tz), 'D MMM YYYY, HH:mm') : __('admin.common.empty_value') }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">{{ __($t.'.panel.last_inbound_at') }}</dt>
                        <dd class="mt-0.5">{{ $conversation->last_inbound_at ? \App\Support\DateFormat::iso($conversation->last_inbound_at->copy()->setTimezone($tz), 'D MMM YYYY, HH:mm') : __('admin.common.empty_value') }}</dd>
                    </div>
                </dl>
            </x-admin.card>

            @include('livewire.admin.telegram.chats.partials.crm-panel')
            @include('livewire.admin.telegram.chats.partials.manage-panel')
        </aside>
    </div>
</div>
