@php($t = 'telegram_inbox')
@php($tz = config('app.timezone'))
@php($title = filled($chat->name) ? $chat->name : __($t.'.web.chat_title', ['id' => $chat->id]))

{{-- Website chat thread (plan 28 C5c). Polls every 10 s while visible; renders only when the chat's version changed. --}}
<div wire:poll.10s.visible="refreshThread" data-web-chat="{{ $chat->id }}">
    <x-admin.page-header :title="$title" :subtitle="__($t.'.web.subtitle', ['id' => $chat->id])">
        <x-slot:breadcrumbs>
            {{ __('menu.groups.chatbot') }} <span class="text-slate-300">/</span>
            <a href="{{ route('admin.telegram.chats') }}" wire:navigate class="hover:text-brand-700">{{ __($t.'.title') }}</a>
            <span class="text-slate-300">/</span> W-{{ $chat->id }}
        </x-slot:breadcrumbs>
        <x-slot:actions>
            <x-admin.button :href="route('admin.telegram.chats')" wire:navigate variant="secondary" icon="chevron-left">{{ __($t.'.show.back') }}</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mt-5 grid gap-4 lg:grid-cols-3 lg:gap-6">
        <div class="space-y-3 lg:col-span-2">
            @if ($chat->isBanned())
                <p class="flex gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-danger" role="alert" data-banner="banned"><x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.web.banner_banned') }}</p>
            @endif
            @if ($chat->isClosed() && ! $chat->isBanned())
                <p class="flex gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700" role="note" data-banner="closed"><x-admin.icon name="info" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.web.banner_closed') }}</p>
            @endif
            @if ($chat->purged_at)
                <p class="flex gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700" role="note" data-banner="purged"><x-admin.icon name="info" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.web.banner_purged') }}</p>
            @endif
            <p class="flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="note" data-banner="unverified"><x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.web.banner_unverified') }}</p>

            <x-admin.card :padding="false">
                <div class="space-y-3 p-4 sm:p-5" data-thread>
                    @forelse ($messages as $message)
                        @if ($message->author === \App\Enums\ChatAuthor::System)
                            <p wire:key="message-{{ $message->id }}" class="text-center text-xs text-slate-500" data-message="{{ $message->id }}" data-system>{{ $message->text }} · {{ \App\Support\DateFormat::iso($message->created_at->copy()->setTimezone($tz), 'D MMM, HH:mm') }}</p>
                        @else
                            @php($visitor = $message->author === \App\Enums\ChatAuthor::Visitor)
                            <div wire:key="message-{{ $message->id }}" @class(['flex', 'justify-start' => $visitor, 'justify-end' => ! $visitor]) data-message="{{ $message->id }}">
                                <div @class(['max-w-[85%] rounded-2xl px-3.5 py-2.5 text-sm', 'bg-slate-100 text-slate-900' => $visitor, 'bg-brand-50 text-slate-900' => ! $visitor])>
                                    <p class="break-words">{!! nl2br(e(preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $message->text) ?? '')) !!}</p>
                                    <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
                                        <span>
                                            @if ($visitor){{ __($t.'.web.from_visitor') }}
                                            @elseif ($message->author === \App\Enums\ChatAuthor::Staff){{ $message->user?->name ?? __($t.'.web.from_staff') }}
                                            @else{{ __($t.'.show.from_bot') }}@if ($message->rule) · {{ $message->rule->name }}@endif
                                            @endif
                                        </span>
                                        <span>{{ \App\Support\DateFormat::iso($message->created_at->copy()->setTimezone($tz), 'D MMM, HH:mm') }}</span>
                                        @if ($message->chat_ai_turn_id !== null)
                                            <x-admin.badge color="outline" data-ai-badge>{{ __($t.'.ai.badge') }}</x-admin.badge>
                                        @endif
                                        @if ($message->lead_id !== null)
                                            <span class="font-semibold" data-lead-ref="{{ $message->lead_id }}">{{ \App\Models\Lead::REFERENCE_PREFIX.$message->lead_id }}</span>
                                        @endif
                                    </p>
                                </div>
                            </div>
                        @endif
                    @empty
                        <p class="py-8 text-center text-sm text-slate-500" data-empty>{{ __($t.'.show.no_messages') }}</p>
                    @endforelse
                </div>
            </x-admin.card>

            @can('reply', $chat)
                <x-admin.card>
                    <form wire:submit="sendReply" class="space-y-3" data-reply-box>
                        <x-admin.textarea
                            wire:model="reply"
                            x-on:keydown.ctrl.enter.prevent.throttle.1500ms="$wire.sendReply()"
                            x-on:keydown.cmd.enter.prevent.throttle.1500ms="$wire.sendReply()"
                            rows="3"
                            :label="__($t.'.reply.label')"
                            :placeholder="__($t.'.reply.placeholder')"
                            :hint="$replyRefusal !== null ? __($t.'.refused.'.$replyRefusal) : __($t.'.reply.hint', ['max' => (int) config('chatbot.web.message_max', 1000)])"
                            maxlength="{{ (int) config('chatbot.web.message_max', 1000) }}"
                            :disabled="$replyRefusal !== null"
                        />
                        <div class="flex justify-end">
                            <x-admin.button type="submit" icon="message" loading="sendReply" :disabled="$replyRefusal !== null" data-reply-send>{{ __($t.'.reply.send') }}</x-admin.button>
                        </div>
                    </form>
                </x-admin.card>
            @endcan
        </div>

        <aside class="space-y-4">
            <x-admin.card :title="__($t.'.web.panel_title')">
                <dl class="space-y-3 text-sm">
                    @foreach ([
                        'name' => filled($chat->name) ? $chat->name : null,
                        'phone' => filled($chat->phone) ? \App\Support\Phone::display($chat->phone) : null,
                    ] as $field => $value)
                        <div>
                            <dt class="text-slate-500">{{ __($t.'.panel.'.$field) }}</dt>
                            <dd class="mt-0.5 flex flex-wrap items-center gap-2 break-words font-medium">
                                {{ $value ?? __('admin.common.empty_value') }}
                                @if ($value !== null)
                                    <x-admin.badge color="outline" data-unverified>{{ __($t.'.web.unverified') }}</x-admin.badge>
                                @endif
                            </dd>
                        </div>
                    @endforeach
                    <div>
                        <dt class="text-slate-500">{{ __($t.'.panel.locale') }}</dt>
                        <dd class="mt-0.5 font-medium">{{ strtoupper($chat->locale) }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">{{ __($t.'.web.first_page') }}</dt>
                        <dd class="mt-0.5 break-all font-medium" data-first-page>{{ $chat->first_page ?? __('admin.common.empty_value') }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">{{ __($t.'.web.consent_at') }}</dt>
                        <dd class="mt-0.5">{{ $chat->consent_at ? \App\Support\DateFormat::iso($chat->consent_at->copy()->setTimezone($tz), 'D MMM YYYY, HH:mm') : __('admin.common.empty_value') }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">{{ __($t.'.panel.status') }}</dt>
                        <dd class="mt-0.5 flex flex-wrap gap-1">
                            <x-admin.badge :color="$chat->isClosed() ? 'muted' : 'sky'">{{ $chat->isClosed() ? \App\Enums\ConversationStatus::Closed->label() : \App\Enums\ConversationStatus::Open->label() }}</x-admin.badge>
                            <x-admin.badge color="outline">{{ $chat->mode->label() }}</x-admin.badge>
                            @if ($chat->isBanned())<x-admin.badge color="red">{{ __($t.'.badges.banned') }}</x-admin.badge>@endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">{{ __($t.'.panel.manager') }}</dt>
                        <dd class="mt-0.5 font-medium">{{ $chat->manager?->name ?? __($t.'.unassigned') }}</dd>
                    </div>
                    @if ($chat->lead_id !== null)
                        <div>
                            <dt class="text-slate-500">{{ __($t.'.web.lead') }}</dt>
                            <dd class="mt-0.5 font-semibold">
                                @if ($canOpenLeads)
                                    <a href="{{ route('admin.leads.show', $chat->lead_id) }}" wire:navigate class="text-brand-700 hover:underline" data-lead-link>{{ \App\Models\Lead::REFERENCE_PREFIX.$chat->lead_id }}</a>
                                @else
                                    {{ \App\Models\Lead::REFERENCE_PREFIX.$chat->lead_id }}
                                @endif
                            </dd>
                        </div>
                    @endif
                </dl>
            </x-admin.card>

            <x-admin.card :title="__($t.'.manage.title')">
                <div class="space-y-3 text-sm" data-manage-panel>
                    @can('assign', $chat)
                        @if ($chat->manager_id === null)
                            <x-admin.button variant="secondary" class="w-full" wire:click="take" loading="take" data-take>{{ __($t.'.manage.take') }}</x-admin.button>
                        @endif
                        <button type="button" wire:click="$toggle('assignOpen')" class="font-semibold text-brand-700 hover:underline" aria-expanded="{{ $assignOpen ? 'true' : 'false' }}" data-assign-toggle>{{ __($t.'.manage.assign') }}</button>
                        @if ($assignOpen)
                            <div class="flex items-end gap-2">
                                <x-admin.select wire:model="assignTarget" :label="__($t.'.manage.assign')" :placeholder="__($t.'.manage.assign_placeholder')" class="min-w-0 flex-1" data-assign-target>
                                    @foreach ($candidates as $candidate)
                                        @continue($candidate->id === $chat->manager_id)
                                        <option value="{{ $candidate->id }}" wire:key="candidate-{{ $candidate->id }}">{{ $candidate->name }}</option>
                                    @endforeach
                                </x-admin.select>
                                <x-admin.button variant="secondary" wire:click="assign" loading="assign" data-assign>{{ __($t.'.manage.assign_btn') }}</x-admin.button>
                            </div>
                        @endif
                    @endcan
                    @can('close', $chat)
                        @if (! $chat->isClosed())
                            <x-admin.button variant="secondary" class="w-full" wire:click="close" loading="close" data-close>{{ __($t.'.manage.close') }}</x-admin.button>
                        @elseif (! $chat->isBanned() && ! $chat->isExpired())
                            <x-admin.button variant="secondary" class="w-full" wire:click="reopen" loading="reopen" data-reopen>{{ __($t.'.manage.reopen') }}</x-admin.button>
                        @endif
                    @endcan
                    @can('ban', $chat)
                        @if ($chat->isBanned())
                            <x-admin.button variant="secondary" class="w-full" wire:click="unban" wire:confirm="{{ __($t.'.web.unban_confirm') }}" loading="unban" data-action-unban>{{ __($t.'.manage.unban') }}</x-admin.button>
                        @else
                            <x-admin.button variant="danger" class="w-full" wire:click="ban" wire:confirm="{{ __($t.'.web.ban_confirm') }}" loading="ban" data-action-ban>{{ __($t.'.web.ban') }}</x-admin.button>
                        @endif
                    @endcan
                    @if ($canConvert)
                        <div class="border-t border-slate-100 pt-3" data-convert-panel>
                            @unless ($convertPhoneUsable)
                                <p class="text-xs text-slate-500" data-convert-no-phone>{{ __($t.'.convert.no_phone') }}</p>
                            @elseif (! $convertOpen)
                                <button type="button" wire:click="openConvert" class="font-semibold text-brand-700 hover:underline" aria-expanded="false" data-convert-toggle>{{ __($t.'.convert.title') }}</button>
                            @else
                                <div class="space-y-2" data-convert-form>
                                    <p class="font-semibold">{{ __($t.'.convert.title') }}</p>
                                    <p class="text-xs text-slate-500">{{ __($t.'.web.convert_hint') }}</p>
                                    <x-admin.select wire:model="convertType" :label="__($t.'.convert.type')" data-convert-type>
                                        @foreach ($convertTypes as $leadType)
                                            <option value="{{ $leadType->value }}" wire:key="convert-type-{{ $leadType->value }}">{{ $leadType->label() }}</option>
                                        @endforeach
                                    </x-admin.select>
                                    <div class="flex gap-2">
                                        <x-admin.button class="min-w-0 flex-1" wire:click="convert" loading="convert" data-convert-submit>{{ __($t.'.convert.submit') }}</x-admin.button>
                                        <x-admin.button variant="secondary" wire:click="closeConvert" data-convert-cancel>{{ __($t.'.convert.cancel') }}</x-admin.button>
                                    </div>
                                </div>
                            @endunless
                        </div>
                    @endif
                </div>
            </x-admin.card>
        </aside>
    </div>
</div>
