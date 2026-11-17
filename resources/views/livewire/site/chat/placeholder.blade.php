{{-- Placeholder of Site\Chat\Widget while it loads (same header, no data). --}}
<div class="flex h-full min-h-0 flex-col" data-chat-placeholder>
    <div class="flex items-center gap-3 bg-ink px-4 py-3 text-white">
        <span class="grid size-9 place-items-center rounded-full bg-brand-600"><x-site.chat.icon class="size-5" /></span>
        <p class="min-w-0 flex-1 truncate text-sm font-bold">{{ __('chatbot.widget.title') }}</p>
        <button type="button" x-on:click="$dispatch('chat-close')" class="grid size-9 place-items-center rounded-btn text-white/80 hover:bg-white/10 hover:text-white" aria-label="{{ __('chatbot.widget.close') }}">
            <x-site.icon name="close" />
        </button>
    </div>
    <div class="grid flex-1 place-items-center bg-bg-soft text-sm text-ink-2" role="status">{{ __('chatbot.widget.loading') }}</div>
</div>
