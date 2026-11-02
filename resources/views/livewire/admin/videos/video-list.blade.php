{{-- YouTube video editor (inside the product / category / brand / news form: no <form>, Enter never submits the parent). --}}
<div x-on:keydown.enter="if ($event.target.tagName === 'INPUT') $event.preventDefault()" @if ($this->polling) wire:poll.5s @endif data-video-list>
    <div class="border-b border-slate-100 px-4 py-3.5 sm:px-6">
        <h2 class="text-base font-semibold">{{ __('admin.videos.title') }}</h2>
        <p class="mt-0.5 text-xs text-slate-500">{{ __('admin.videos.help') }}</p>
    </div>

    @if ($canEdit)
        <div class="space-y-3 border-b border-slate-100 bg-slate-50/60 p-4 sm:p-6" data-video-add>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                <x-admin.input wire:model="form.url" wire:keydown.enter="add" class="min-w-0 flex-1" :label="__('admin.videos.link')" :hint="__('admin.videos.link_hint')" maxlength="500" autocomplete="off" inputmode="url" />
                <x-admin.button class="sm:mt-7" size="sm" icon="plus" wire:click="add" loading="add" data-video-add-button>{{ __('admin.videos.add') }}</x-admin.button>
            </div>
        </div>
    @else
        <p class="border-b border-slate-100 px-4 py-3 text-xs text-slate-500 sm:px-6" data-video-read-only>{{ __('admin.videos.read_only') }}</p>
    @endif

    <ol class="divide-y divide-slate-100">
        @forelse ($this->videos as $video)
            @php
                $label = $video->displayTitle(app()->getLocale()) ?? $video->source_title ?? __('admin.videos.untitled');
                $thumbnail = $video->thumbnailUrl();
            @endphp
            <li wire:key="video-{{ $video->id }}" class="px-4 py-3 sm:px-6" data-video-item="{{ $video->id }}" @if (! $video->is_active) data-video-inactive @endif>
                <div class="flex items-start gap-3">
                    <div class="aspect-video w-28 shrink-0 overflow-hidden rounded-lg bg-slate-100 sm:w-36">
                        @if ($thumbnail)
                            <img src="{{ $thumbnail }}" alt="" width="288" height="162" loading="lazy" class="size-full object-cover">
                        @else
                            <div class="grid size-full place-items-center text-slate-300" title="{{ __('admin.videos.no_preview') }}"><x-admin.icon name="image" size="size-8" /></div>
                        @endif
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="break-words text-sm font-medium">{{ $label }}</p>
                        @if ($video->source_title && $video->source_title !== $label)
                            <p class="mt-0.5 break-words text-xs text-slate-500">{{ __('admin.videos.source') }}: {{ $video->source_title }}</p>
                        @endif
                        @if ($video->source_author)
                            <p class="mt-0.5 text-xs text-slate-500">{{ __('admin.videos.channel') }}: {{ $video->source_author }}</p>
                        @endif
                        <p class="mt-1 flex flex-wrap items-center gap-1.5">
                            <x-admin.badge :color="$video->fetch_status->color()">{{ $video->fetch_status->label() }}</x-admin.badge>
                            @unless ($video->is_active)
                                <x-admin.badge color="amber">{{ __('admin.videos.awaiting_review') }}</x-admin.badge>
                            @endunless
                            @unless ($video->source_title_safe)
                                <x-admin.badge color="red" data-video-unsafe>{{ __('admin.videos.unsafe_title') }}</x-admin.badge>
                            @endunless
                            @if ($video->published_on)
                                <span class="text-xs text-slate-400">{{ $video->published_on->format('d.m.Y') }}</span>
                            @endif
                        </p>
                    </div>
                </div>

                <div class="mt-2 flex flex-wrap items-center justify-end gap-1">
                    <a href="{{ $video->watchUrl() }}" target="_blank" rel="noopener noreferrer" class="inline-flex h-9 items-center gap-1.5 rounded-lg px-2.5 text-xs font-semibold text-slate-600 hover:bg-slate-100" data-video-open><x-admin.icon name="external" size="size-4" />{{ __('admin.videos.open_youtube') }}</a>
                    @if ($canEdit)
                        <label class="inline-flex h-9 cursor-pointer items-center gap-2 rounded-lg px-2.5 text-xs font-semibold text-slate-600 hover:bg-slate-100">
                            <input type="checkbox" role="switch" class="size-4 rounded border-slate-300 text-brand-600" @checked($video->is_active) wire:click="toggle({{ $video->id }})" data-video-toggle>
                            {{ __('admin.videos.active') }}
                        </label>
                        <button type="button" wire:click="refresh({{ $video->id }})" class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="{{ __('admin.videos.refresh') }}: {{ $label }}" title="{{ __('admin.videos.refresh') }}" data-video-refresh><x-admin.icon name="restore" size="size-4" /></button>
                        <button type="button" wire:click="move({{ $video->id }}, -1)" @disabled($loop->first) class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30" aria-label="{{ __('admin.common.move_up') }}: {{ $label }}"><x-admin.icon name="chevron-down" size="size-4" class="rotate-180" /></button>
                        <button type="button" wire:click="move({{ $video->id }}, 1)" @disabled($loop->last) class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30" aria-label="{{ __('admin.common.move_down') }}: {{ $label }}"><x-admin.icon name="chevron-down" size="size-4" /></button>
                        <button type="button" wire:click="edit({{ $video->id }})" class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="{{ __('admin.common.edit') }}: {{ $label }}" data-video-edit><x-admin.icon name="edit" size="size-4" /></button>
                        <button
                            type="button"
                            x-on:click="$dispatch('confirm', {
                                title: @js(__('admin.videos.delete_title')),
                                body: @js(__('admin.videos.delete_body', ['name' => $label])),
                                label: @js(__('admin.common.delete')),
                                component: $wire.$id, method: 'delete', params: [{{ $video->id }}],
                            })"
                            class="grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-danger"
                            aria-label="{{ __('admin.common.delete') }}: {{ $label }}"
                        ><x-admin.icon name="trash" size="size-4" /></button>
                    @endif
                </div>

                @if ($canEdit && $editingId === $video->id)
                    <div class="mt-3 space-y-4 rounded-xl border border-slate-200 bg-slate-50/60 p-4" data-video-editor>
                        <x-admin.locale-tabs :error-keys="['form.title']" :label="__('admin.form.content_language')">
                            @foreach (config('app.supported_locales') as $locale)
                                <x-admin.locale-panel :locale="$locale">
                                    <x-admin.input wire:model="form.title.{{ $locale }}" :label="__('admin.videos.manual_title')" :hint="__('admin.videos.manual_title_hint')" maxlength="{{ \App\Models\Video::TITLE_MAX }}" />
                                </x-admin.locale-panel>
                            @endforeach
                        </x-admin.locale-tabs>
                        <x-admin.input wire:model="form.published_on" type="date" :label="__('admin.videos.published_on')" :hint="__('admin.videos.published_on_hint')" max="{{ today()->format('Y-m-d') }}" />
                        <div class="flex justify-end gap-2">
                            <x-admin.button variant="secondary" size="sm" wire:click="cancel">{{ __('admin.common.cancel') }}</x-admin.button>
                            <x-admin.button size="sm" wire:click="save" loading="save" data-video-save>{{ __('admin.common.save') }}</x-admin.button>
                        </div>
                    </div>
                @endif
            </li>
        @empty
            <li class="px-4 py-6 text-center text-sm text-slate-500 sm:px-6" data-video-empty>{{ __('admin.videos.empty') }}</li>
        @endforelse
    </ol>
</div>
