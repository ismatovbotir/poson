{{--
    Website chat launcher (plan 28 C5b §6, §19.3, §19.5, CB-23, WC-18), in layouts::site only.
    A static button with an inline icon and an aria-label (no text node, no image, fixed: no layout
    shift, hidden in print). No Livewire request, cookie or session write before the first click: the
    widget (Site\Chat\Widget) sits in an x-if template and is loaded lazily when the visitor opens it.
    Only a chat cookie with recent staff activity loads it right after the page (deferred) and shows
    the unread dot. State and visibility: App\Support\Chat\WebChatLauncher::state().
    Plan 42 (QM-10): opening the quote modal ("lead-modal-opened") closes the panel without moving focus.
--}}
@php
    $chatLauncher = \App\Support\Chat\WebChatLauncher::state();
@endphp

@if ($chatLauncher !== null)
    @php
        $chatRight = $chatLauncher['position'] === 'right';
        $chatRaised = $chatLauncher['raised'];
    @endphp
    <div
        x-data="{ open: false, mounted: @js($chatLauncher['autoMount']), unread: @js($chatLauncher['autoMount']) }"
        x-on:lead-modal-opened.window="open = false"
        x-on:chat-close.window="open = false; $nextTick(() => $refs.chatLauncherButton?.focus())"
        x-on:chat-updated.window="if (! open) unread = true"
        x-on:keydown.escape.window="if (open) { open = false; $nextTick(() => $refs.chatLauncherButton?.focus()) }"
        @class(['print:hidden', 'max-sm:hidden' => ! $chatLauncher['mobile']])
        data-chat-launcher
    >
        <button
            type="button"
            x-ref="chatLauncherButton"
            x-on:click="mounted = true; open = ! open; unread = false"
            x-bind:aria-expanded="open ? 'true' : 'false'"
            x-bind:aria-label="unread ? @js(__('chatbot.widget.open').' · '.__('chatbot.widget.unread')) : @js(__('chatbot.widget.open'))"
            aria-expanded="false"
            aria-controls="site-chat"
            aria-label="{{ __('chatbot.widget.open') }}"
            @class([
                'fixed z-40 grid size-14 place-items-center rounded-full bg-brand-600 text-white shadow-card transition-colors hover:bg-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600',
                'right-4 sm:right-6' => $chatRight,
                'left-4 sm:left-6' => ! $chatRight,
                'bottom-[88px] md:bottom-6' => $chatRaised,
                'bottom-4 sm:bottom-6' => ! $chatRaised,
            ])
            data-chat-open
        >
            <x-site.chat.icon class="size-7" />
            <span x-show="unread" x-cloak class="absolute top-0.5 right-0.5 size-3.5 rounded-full bg-danger ring-2 ring-white" data-chat-unread></span>
        </button>

        <template x-if="mounted">
            <section
                id="site-chat"
                x-show="open"
                x-cloak
                role="dialog"
                aria-label="{{ __('chatbot.widget.title') }}"
                @class([
                    'fixed inset-0 z-[55] flex flex-col overflow-hidden bg-white sm:inset-auto sm:h-[520px] sm:max-h-[calc(100dvh-8rem)] sm:w-[360px] sm:rounded-box-lg sm:border sm:border-line sm:shadow-card',
                    'sm:right-6' => $chatRight,
                    'sm:left-6' => ! $chatRight,
                    'sm:bottom-[160px] md:bottom-24' => $chatRaised,
                    'sm:bottom-24' => ! $chatRaised,
                ])
            >
                @if ($chatLauncher['autoMount'])
                    <livewire:site.chat.widget defer :page="$chatLauncher['page']" />
                @else
                    <livewire:site.chat.widget lazy :page="$chatLauncher['page']" />
                @endif
            </section>
        </template>
    </div>
@endif
