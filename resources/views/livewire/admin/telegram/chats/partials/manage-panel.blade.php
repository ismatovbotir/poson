@php($t = 'telegram_inbox')

{{-- Assign / take, close / reopen and ban (plan 21 A3, A4). Buttons follow the policy; the actions check again. --}}
<x-admin.card :title="__($t.'.manage.title')">
    <div class="space-y-3 text-sm" data-manage-panel>
        @can('assign', $conversation)
            @if ($conversation->isRegistered())
                @if ($conversation->manager_id === null)
                    <x-admin.button variant="secondary" class="w-full" wire:click="take" loading="take" data-take>{{ __($t.'.manage.take') }}</x-admin.button>
                @endif
                <button type="button" wire:click="$toggle('assignOpen')" class="font-semibold text-brand-700 hover:underline" aria-expanded="{{ $assignOpen ? 'true' : 'false' }}" data-assign-toggle>{{ __($t.'.manage.assign') }}</button>
                @if ($assignOpen)
                <div class="flex items-end gap-2">
                    <x-admin.select wire:model="assignTarget" :label="__($t.'.manage.assign')" :placeholder="__($t.'.manage.assign_placeholder')" class="min-w-0 flex-1" data-assign-target>
                        @foreach ($candidates as $candidate)
                            @continue($candidate->id === $conversation->manager_id)
                            <option value="{{ $candidate->id }}" wire:key="candidate-{{ $candidate->id }}">{{ $candidate->name }}</option>
                        @endforeach
                    </x-admin.select>
                    <x-admin.button variant="secondary" wire:click="assign" loading="assign" data-assign>{{ __($t.'.manage.assign_btn') }}</x-admin.button>
                </div>
                @endif
            @endif
        @endcan
        @can('close', $conversation)
            @if ($conversation->conversation_status === \App\Enums\ConversationStatus::Open)
                <x-admin.button variant="secondary" class="w-full" wire:click="close" loading="close" data-close>{{ __($t.'.manage.close') }}</x-admin.button>
            @else
                <x-admin.button variant="secondary" class="w-full" wire:click="reopen" loading="reopen" data-reopen>{{ __($t.'.manage.reopen') }}</x-admin.button>
            @endif
        @endcan
        @can('ban', $conversation)
            @if ($conversation->isBanned())
                <x-admin.button variant="secondary" class="w-full" wire:click="unban" wire:confirm="{{ __($t.'.manage.unban_confirm') }}" loading="unban" data-action-unban>{{ __($t.'.manage.unban') }}</x-admin.button>
            @else
                <x-admin.button variant="danger" class="w-full" wire:click="ban" wire:confirm="{{ __($t.'.manage.ban_confirm') }}" loading="ban" data-action-ban>{{ __($t.'.manage.ban') }}</x-admin.button>
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
                        <p class="text-xs text-slate-500">{{ __($t.'.convert.hint') }}</p>
                        <p class="text-xs text-slate-500" data-convert-text-only>{{ __($t.'.convert.text_only') }}</p>
                        @if ($convertCustomerSkipped)
                            <p class="rounded-lg border border-amber-200 bg-amber-50 p-2 text-xs text-amber-900" role="note" data-convert-no-customer>{{ __($t.'.convert.customer_not_attached') }}</p>
                        @endif
                        @if ($convertHasRecentLead)
                            <p class="rounded-lg border border-amber-200 bg-amber-50 p-2 text-xs text-amber-900" role="note" data-convert-recent-lead>{{ __($t.'.convert.recent_lead', ['days' => \App\Models\Lead::DUPLICATE_WINDOW_DAYS]) }}</p>
                        @endif
                        <x-admin.select wire:model="convertType" :label="__($t.'.convert.type')" data-convert-type>
                            @foreach ($convertTypes as $leadType)
                                <option value="{{ $leadType->value }}" wire:key="convert-type-{{ $leadType->value }}">{{ $leadType->label() }}</option>
                            @endforeach
                        </x-admin.select>
                        <p class="text-xs text-slate-500" data-convert-count>{{ __($t.'.convert.selected', ['count' => count($convertSelected), 'max' => \App\Actions\Telegram\ConvertTelegramToLead::MAX_MESSAGES]) }}</p>
                        <p @class(['text-xs', 'text-slate-500' => ! $convertTextTooLong, 'font-semibold text-danger' => $convertTextTooLong]) data-convert-length>{{ __($t.'.convert.text_length', ['length' => $convertTextLength, 'max' => \App\Actions\Leads\CreateLead::MESSAGE_LIMIT]) }}</p>
                        <div class="flex gap-2">
                            <x-admin.button class="min-w-0 flex-1" wire:click="convert" loading="convert" :disabled="$convertSelected === [] || $convertTextTooLong" data-convert-submit>{{ __($t.'.convert.submit') }}</x-admin.button>
                            <x-admin.button variant="secondary" wire:click="closeConvert" data-convert-cancel>{{ __($t.'.convert.cancel') }}</x-admin.button>
                        </div>
                    </div>
                @endunless
            </div>
        @endif
        @can('delete', $conversation)
            <div class="border-t border-slate-100 pt-3">
                <button type="button" wire:click="$toggle('deleteOpen')" class="font-semibold text-danger hover:underline" aria-expanded="{{ $deleteOpen ? 'true' : 'false' }}" data-delete-toggle>{{ __($t.'.manage.delete') }}</button>
                @if ($deleteOpen)
                    <div class="mt-2 space-y-2" data-delete-panel>
                        <p class="text-xs text-slate-500">{{ __($t.'.manage.delete_help') }}</p>
                        @foreach ($deleteWarnings as $warning)
                            <p class="rounded-lg border border-amber-200 bg-amber-50 p-2 text-xs text-amber-900" role="note" wire:key="delete-warning-{{ $loop->index }}" data-delete-warning>{{ $warning }}</p>
                        @endforeach
                        @unless ($passwordConfirmed)
                            <p class="text-xs text-slate-500" data-password-required>{{ __($t.'.manage.delete_password') }}</p>
                        @endunless
                        <x-admin.button variant="danger" class="w-full" wire:click="deleteConversation" wire:confirm="{{ implode(' ', [__($t.'.manage.delete_confirm'), ...$deleteWarnings]) }}" loading="deleteConversation" data-action-delete>{{ __($t.'.manage.delete_btn') }}</x-admin.button>
                    </div>
                @endif
            </div>
        @endcan
        <div class="border-t border-slate-100 pt-3">
            <button type="button" wire:click="$toggle('historyOpen')" class="font-semibold text-brand-700 hover:underline" aria-expanded="{{ $historyOpen ? 'true' : 'false' }}" data-history-toggle>{{ __($t.'.history.title') }}</button>
            @if ($historyOpen)
                @if ($history === [])
                    <p class="mt-2 text-xs text-slate-500" data-history-empty>{{ __($t.'.history.empty') }}</p>
                @else
                    <ul class="mt-2 space-y-2" data-history>
                        @foreach ($history as $entry)
                            <li wire:key="history-{{ $entry['id'] }}">
                                <span class="block break-words">{{ $entry['text'] }}</span>
                                <span class="block text-xs text-slate-500">{{ \App\Support\DateFormat::iso($entry['at']->copy()->setTimezone(config('app.timezone')), 'D MMM YYYY, HH:mm') }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </div>
    </div>
</x-admin.card>
