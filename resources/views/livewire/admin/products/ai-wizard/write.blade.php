{{-- AI wizard step "write" (plan 32 §5); variables from AiWizard::render(). --}}
<x-admin.card :title="__($t.'.write.title')" data-step="write">
    @if ($pending)
        <div wire:poll.2s="poll" class="flex flex-wrap items-center gap-3 rounded-xl bg-brand-50 p-4 text-sm text-brand-800" role="status" data-pending="writing">
            <svg class="size-5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25" /><path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3" /></svg>
            <span class="min-w-0 flex-1">{{ __($t.'.write.writing') }}</span>
        </div>
    @else
        <div class="flex justify-start">
            <x-admin.button variant="secondary" icon="chevron-left" wire:click="goTo('facts')">{{ __($t.'.back') }}</x-admin.button>
        </div>
    @endif
</x-admin.card>
