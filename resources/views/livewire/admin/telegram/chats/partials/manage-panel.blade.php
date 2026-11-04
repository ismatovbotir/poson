@php($t = 'telegram_inbox')

{{-- Assign / take and close / reopen (plan 21 A3). Buttons follow the policy; the actions check again. --}}
<x-admin.card :title="__($t.'.manage.title')">
    <div class="space-y-3 text-sm" data-manage-panel>
        @can('assign', $conversation)
            @if ($conversation->isRegistered())
                @if ($conversation->manager_id === null)
                    <x-admin.button variant="secondary" class="w-full" wire:click="take" loading="take" data-take>{{ __($t.'.manage.take') }}</x-admin.button>
                @endif
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
        @endcan
        @can('close', $conversation)
            @if ($conversation->conversation_status === \App\Enums\ConversationStatus::Open)
                <x-admin.button variant="secondary" class="w-full" wire:click="close" loading="close" data-close>{{ __($t.'.manage.close') }}</x-admin.button>
            @else
                <x-admin.button variant="secondary" class="w-full" wire:click="reopen" loading="reopen" data-reopen>{{ __($t.'.manage.reopen') }}</x-admin.button>
            @endif
        @endcan
    </div>
</x-admin.card>
