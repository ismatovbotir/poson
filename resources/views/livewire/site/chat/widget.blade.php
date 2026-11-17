{{--
    Website chat widget (App\Livewire\Site\Chat\Widget, plan 28 C5b). Every text is printed escaped
    ({{ }}); buttons and links come from server data only (stored buttons by index, product links
    built with route(), url buttons re-validated). Staff are "Manager", never a name (WC-12).
    The poll is the Alpine back-off timer posonWebChat (resources/js/web-chat.js), never wire:poll.
--}}
@php
    $notices = ['too_many', 'wait_staff', 'unavailable', 'ended'];
    $inputClass = 'h-10 w-full rounded-btn border-[1.5px] border-line bg-white px-3 text-sm text-ink placeholder:text-ink-2 focus:border-brand-600 focus:outline-none';
    $chipClass = 'inline-flex min-h-9 items-center rounded-full border border-brand-600/40 bg-white px-3.5 py-1.5 text-left text-[13px] font-semibold text-brand-700 transition-colors hover:border-brand-600 hover:bg-brand-50 disabled:opacity-60';
@endphp

<div
    class="flex h-full min-h-0 flex-col"
    x-data="posonWebChat({ mode: @js($mode), pending: @js($pending) })"
    data-chat-widget
    data-mode="{{ $mode }}"
>
    <div class="flex items-center gap-2 bg-ink px-3 py-3 text-white">
        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-brand-600"><x-site.chat.icon class="size-5" /></span>
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-bold">{{ __('chatbot.widget.title') }}</p>
            <p class="truncate text-xs text-ink-soft" data-chat-status>{{ $mode === 'manager' ? __('chatbot.widget.staff') : __('chatbot.widget.subtitle') }}</p>
        </div>
        @if ($chat !== null)
            <button type="button" wire:click="menu" class="rounded-btn px-2 py-1.5 text-xs font-semibold text-white/85 hover:bg-white/10 hover:text-white" data-chat-menu>{{ __('chatbot.widget.menu') }}</button>
            <button type="button" wire:click="end" wire:confirm="{{ __('chatbot.widget.end_confirm') }}" class="rounded-btn px-2 py-1.5 text-xs font-semibold text-white/85 hover:bg-white/10 hover:text-white" data-chat-end>{{ __('chatbot.widget.end') }}</button>
        @endif
        <button type="button" x-on:click="$dispatch('chat-close')" class="grid size-9 shrink-0 place-items-center rounded-btn text-white/80 hover:bg-white/10 hover:text-white" aria-label="{{ __('chatbot.widget.close') }}">
            <x-site.icon name="close" />
        </button>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain bg-bg-soft px-3 py-4" x-ref="list" data-chat-messages>
        <div class="flex flex-col gap-3" aria-live="polite">
            @if (! $available)
                <p class="rounded-box border border-line bg-white px-3.5 py-3 text-sm text-ink-2" data-chat-unavailable>{{ __('chatbot.widget.unavailable') }}</p>
            @else
                @if ($chat === null)
                    <div class="max-w-[88%] rounded-box rounded-tl-sm bg-white px-3.5 py-2.5 text-sm shadow-card" data-chat-greeting>
                        <p class="text-[11px] font-semibold text-ink-2">{{ __('chatbot.widget.bot') }}</p>
                        <p class="break-words whitespace-pre-line">{{ $notice === 'ended' ? __('chatbot.widget.ended') : $menuText }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2" data-chat-topics>
                        @foreach ($menuTopics as $topic)
                            <button type="button" wire:key="topic-{{ $topic['id'] }}" wire:click="topic({{ (int) $topic['id'] }})" wire:loading.attr="disabled" class="{{ $chipClass }}">{{ $topic['label'] }}</button>
                        @endforeach
                        <button type="button" wire:click="handoff" wire:loading.attr="disabled" class="{{ $chipClass }}" data-chat-handoff>{{ __('chatbot.widget.handoff') }}</button>
                    </div>
                @endif

                @foreach ($messages as $message)
                    @php
                        $own = $message->author === \App\Enums\ChatAuthor::Visitor;
                        $isLatest = $message->id === $latestBotId;
                        $productButtons = $message->author === \App\Enums\ChatAuthor::Bot && is_array($message->buttons)
                            ? array_filter($message->buttons, fn ($button) => is_array($button) && ($button['type'] ?? null) === 'product' && is_int($button['id'] ?? null) && isset($cards[$button['id']]))
                            : [];
                    @endphp
                    <div wire:key="chat-message-{{ $message->id }}" @class(['flex flex-col gap-2', 'items-end' => $own, 'items-start' => ! $own]) data-chat-message="{{ $message->author->value }}">
                        <div @class([
                            'max-w-[88%] rounded-box px-3.5 py-2.5 text-sm',
                            'rounded-tr-sm bg-brand-600 text-white' => $own,
                            'rounded-tl-sm bg-white text-ink shadow-card' => ! $own,
                        ])>
                            @unless ($own)
                                <p class="text-[11px] font-semibold text-ink-2" data-chat-author>{{ $message->author === \App\Enums\ChatAuthor::Staff ? __('chatbot.widget.staff') : __('chatbot.widget.bot') }}</p>
                            @endunless
                            <p class="break-words whitespace-pre-line">{{ $message->text }}</p>
                        </div>

                        @if ($productButtons !== [])
                            <div class="grid w-full gap-2" data-chat-products>
                                @foreach ($productButtons as $index => $button)
                                    @php
                                        $card = $cards[$button['id']];
                                    @endphp
                                    <div wire:key="chat-card-{{ $message->id }}-{{ $index }}" class="flex gap-3 rounded-box border border-line bg-white p-2.5" data-chat-product>
                                        <a href="{{ $card->url }}" class="grid size-16 shrink-0 place-items-center rounded-btn bg-bg-soft" tabindex="-1" aria-hidden="true">
                                            @if ($card->image)
                                                <img src="{{ $card->image }}" alt="" width="64" height="64" loading="lazy" decoding="async" class="size-14 object-contain">
                                            @else
                                                <svg viewBox="0 0 120 120" class="size-12" aria-hidden="true" focusable="false"><use href="#{{ $card->placeholder }}" /></svg>
                                            @endif
                                        </a>
                                        <div class="flex min-w-0 flex-1 flex-col gap-1">
                                            <a href="{{ $card->url }}" class="line-clamp-2 text-[13px] leading-snug font-semibold hover:text-brand-600">{{ $card->name }}</a>
                                            <p class="text-sm font-extrabold" data-price>
                                                @if ($card->price !== null)
                                                    {{ $card->pricedPerLabel ? __('catalog.roll.per_label', ['price' => \App\Support\Money::format($card->price)]) : \App\Support\Money::format($card->price) }}
                                                @else
                                                    <span class="font-semibold text-ink-2">{{ __('chatbot.widget.price_on_request') }}</span>
                                                @endif
                                            </p>
                                            @if ($isLatest)
                                                <button type="button" wire:click="press({{ (int) $index }})" wire:loading.attr="disabled" class="self-start text-xs font-semibold text-brand-600 hover:text-brand-700 hover:underline">{{ __('chatbot.widget.quote') }}</button>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if ($isLatest && $actions !== [])
                            <div class="flex flex-wrap gap-2" data-chat-actions>
                                @foreach ($actions as $action)
                                    @if ($action['type'] === 'url')
                                        <a href="{{ $action['url'] }}" wire:key="chat-action-{{ $action['index'] }}" class="{{ $chipClass }}" rel="noopener">{{ $action['label'] }}</a>
                                    @else
                                        <button type="button" wire:key="chat-action-{{ $action['index'] }}" wire:click="press({{ (int) $action['index'] }})" wire:loading.attr="disabled" class="{{ $chipClass }}">{{ $action['label'] }}</button>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach

                @if ($pending)
                    <div class="max-w-[60%] rounded-box rounded-tl-sm bg-white px-3.5 py-2.5 text-sm text-ink-2 shadow-card" role="status" data-chat-typing>{{ __('chatbot.widget.typing') }}</div>
                @endif

                @if ($form !== null)
                    <form wire:submit="submitLead" class="grid gap-2.5 rounded-box border border-line bg-white p-3.5" data-chat-form="{{ $form['flow']->value }}">
                        <p class="text-sm font-bold">{{ __('chatbot.widget.form_title.'.$form['flow']->value) }}</p>
                        @if ($formProduct !== null)
                            <p class="text-[13px] text-ink-2" data-chat-form-product>{{ $formProduct }}</p>
                        @endif
                        <div role="alert">
                            @error('form')
                                <p class="rounded-btn border border-danger/30 bg-danger/5 px-3 py-2 text-[13px] font-medium text-danger" data-form-error>{{ $message }}</p>
                            @enderror
                        </div>
                        @foreach (['name' => ['text', 'name', 120], 'phone' => ['tel', 'tel', 32]] as $field => [$type, $autocomplete, $max])
                            <div class="grid gap-1" wire:key="chat-form-{{ $field }}">
                                <label for="chat-{{ $field }}" class="text-[13px] font-semibold">{{ __('chatbot.widget.'.$field) }} <span class="text-danger" aria-hidden="true">*</span></label>
                                <input id="chat-{{ $field }}" type="{{ $type }}" wire:model="{{ $field }}" autocomplete="{{ $autocomplete }}" maxlength="{{ $max }}" aria-required="true" @if ($field === 'phone') inputmode="tel" placeholder="{{ __('site.lead_form.phone_placeholder') }}" @endif @error($field) aria-invalid="true" aria-describedby="chat-{{ $field }}-error" @enderror class="{{ $inputClass }}">
                                @error($field)
                                    <p id="chat-{{ $field }}-error" class="text-xs text-danger">{{ $message }}</p>
                                @enderror
                            </div>
                        @endforeach
                        <div class="grid gap-1">
                            <label for="chat-details" class="text-[13px] font-semibold">{{ __('chatbot.widget.details') }}</label>
                            <textarea id="chat-details" wire:model="details" rows="2" maxlength="1000" placeholder="{{ __('chatbot.widget.details_hint.'.$form['flow']->value) }}" @error('details') aria-invalid="true" aria-describedby="chat-details-error" @enderror class="{{ str_replace('h-10', 'min-h-16 py-2', $inputClass) }}"></textarea>
                            @error('details')
                                <p id="chat-details-error" class="text-xs text-danger">{{ $message }}</p>
                            @enderror
                        </div>
                        {{-- Honeypot: off-screen and skipped by keyboard and screen readers. --}}
                        <div class="absolute -left-[10000px] size-px overflow-hidden" aria-hidden="true">
                            <input type="text" name="fax_ext" wire:model="fax_ext" tabindex="-1" autocomplete="off">
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" wire:loading.attr="disabled" wire:target="submitLead" class="inline-flex h-10 flex-1 items-center justify-center rounded-btn bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-70">{{ __('chatbot.widget.submit') }}</button>
                            <button type="button" wire:click="menu" class="inline-flex h-10 items-center justify-center rounded-btn border border-line px-3 text-sm font-semibold text-ink-2 hover:text-ink">{{ __('chatbot.widget.cancel') }}</button>
                        </div>
                    </form>
                @endif

                @if ($leadSent || $leadDone)
                    <p class="rounded-box border border-brand-600/30 bg-brand-50 px-3.5 py-2.5 text-sm text-brand-700" role="status" data-chat-lead-sent>{{ $leadSent ? __('chatbot.widget.lead_sent') : __('chatbot.widget.lead_done') }}</p>
                @endif

                @if ($mode === 'manager')
                    <p class="text-center text-xs text-ink-2" data-chat-waiting>{{ $afterHours ? __('chatbot.widget.after_hours') : __('chatbot.widget.waiting') }}</p>
                @endif

                @if ($showContact)
                    <form wire:submit="saveContact" x-data="{ skipped: false }" x-show="! skipped" class="grid gap-2 rounded-box border border-line bg-white p-3.5" data-chat-contact>
                        <p class="text-[13px] font-semibold">{{ __('chatbot.widget.contact_title') }}</p>
                        @error('contact')
                            <p class="text-xs text-danger" role="alert">{{ $message }}</p>
                        @enderror
                        <label for="chat-contact-name" class="sr-only">{{ __('chatbot.widget.contact_name') }}</label>
                        <input id="chat-contact-name" type="text" wire:model="contactName" autocomplete="name" maxlength="64" placeholder="{{ __('chatbot.widget.contact_name') }}" class="{{ $inputClass }}">
                        <label for="chat-contact-phone" class="sr-only">{{ __('chatbot.widget.contact_phone') }}</label>
                        <input id="chat-contact-phone" type="tel" inputmode="tel" wire:model="contactPhone" autocomplete="tel" maxlength="32" placeholder="{{ __('site.lead_form.phone_placeholder') }}" @error('contactPhone') aria-invalid="true" aria-describedby="chat-contact-phone-error" @enderror class="{{ $inputClass }}">
                        @error('contactPhone')
                            <p id="chat-contact-phone-error" class="text-xs text-danger">{{ $message }}</p>
                        @enderror
                        <div class="flex gap-2">
                            <button type="submit" wire:loading.attr="disabled" wire:target="saveContact" class="inline-flex h-9 flex-1 items-center justify-center rounded-btn bg-brand-600 px-3 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-70">{{ __('chatbot.widget.contact_save') }}</button>
                            <button type="button" x-on:click="skipped = true" class="inline-flex h-9 items-center justify-center rounded-btn border border-line px-3 text-sm font-semibold text-ink-2 hover:text-ink">{{ __('chatbot.widget.contact_skip') }}</button>
                        </div>
                    </form>
                @elseif ($contactSaved)
                    <p class="text-center text-xs text-ink-2" role="status" data-chat-contact-saved>{{ __('chatbot.widget.contact_saved') }}</p>
                @endif
            @endif

            @if ($notice !== null && $notice !== 'ended' && in_array($notice, $notices, true) && $available)
                <p class="rounded-btn bg-warn/10 px-3 py-2 text-[13px] text-ink" role="status" data-chat-notice="{{ $notice }}">{{ __('chatbot.widget.'.$notice) }}</p>
            @endif
        </div>
    </div>

    <div x-show="stalled || failed" x-cloak class="border-t border-line bg-white px-3 py-2 text-center">
        <p x-show="failed" class="mb-1 text-xs text-ink-2">{{ __('chatbot.widget.error') }}</p>
        <button type="button" x-show="stalled" x-on:click="checkNow()" class="text-[13px] font-semibold text-brand-600 hover:text-brand-700 hover:underline" data-chat-check>{{ __('chatbot.widget.check_replies') }}</button>
    </div>

    @if ($available)
        <form x-on:submit.prevent="submit()" class="border-t border-line bg-white px-3 pt-2 pb-3" data-chat-input>
            <p class="mb-2 text-[11px] leading-snug text-ink-2" data-chat-consent>{{ $consent }} <a href="{{ $privacyUrl }}" class="font-medium text-brand-600 underline hover:text-brand-700" target="_blank" rel="noopener">{{ __('chatbot.widget.consent_link') }}</a></p>
            <div class="flex items-end gap-2">
                <label for="chat-text" class="sr-only">{{ __('chatbot.widget.placeholder') }}</label>
                <textarea
                    id="chat-text"
                    x-model="draft"
                    x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); submit() }"
                    rows="1"
                    maxlength="1000"
                    placeholder="{{ __('chatbot.widget.placeholder') }}"
                    class="max-h-28 min-h-10 flex-1 resize-none rounded-btn border-[1.5px] border-line bg-white px-3 py-2 text-sm text-ink placeholder:text-ink-2 focus:border-brand-600 focus:outline-none"
                ></textarea>
                <button type="submit" x-bind:disabled="sending || draft.trim() === ''" class="grid size-10 shrink-0 place-items-center rounded-btn bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-50" aria-label="{{ __('chatbot.widget.send') }}">
                    <x-site.icon name="send" />
                </button>
            </div>
        </form>
    @endif
</div>
