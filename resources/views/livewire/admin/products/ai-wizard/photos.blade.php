{{-- AI wizard step "photos" (plan 32 §5); variables from AiWizard::render(). --}}
<x-admin.card :title="__($t.'.photos.title')" :description="$improve ? __($t.'.photos.help_improve') : __($t.'.photos.help_create', ['max' => $maxImages])" data-step="photos">
    <div class="space-y-5">
        <div class="flex flex-wrap items-start gap-3 rounded-lg bg-amber-50 px-3 py-2.5 text-sm text-amber-800" role="note" data-pii-hint="photos">
            <x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />
            <p class="min-w-0 flex-1">{{ __($t.'.photos.pii') }}</p>
            @unless ($photoHint)
                <x-admin.button size="sm" variant="secondary" wire:click="acknowledgePhotoHint" loading="acknowledgePhotoHint" data-ack>{{ __($t.'.photos.acknowledge') }}</x-admin.button>
            @endunless
        </div>

        @if ($photoHint)
            <label class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-slate-300 bg-canvas px-4 py-8 text-center text-sm text-slate-600 hover:border-brand-500" data-upload>
                <x-admin.icon name="upload" size="size-6" class="text-slate-400" />
                <span class="font-semibold">{{ __($t.'.photos.upload') }}</span>
                <span class="text-xs text-slate-500">{{ __($t.'.photos.upload_hint', ['max' => $maxImages]) }}</span>
                <input type="file" wire:model="upload" accept="image/jpeg,image/png,image/webp" class="sr-only">
                <span wire:loading wire:target="upload" class="text-xs font-semibold text-brand-700">{{ __('admin.image_upload.processing') }}</span>
            </label>
        @endif
        <x-admin.field-error name="upload" />

        @if ($tiles !== [])
            <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
                @foreach ($tiles as $tile)
                    @php
                        $flags = $tile['flags'];
                        $removed = $flags['action'] === 'remove';
                        $isMain = $mainImage === $tile['index'] && ! $removed;
                        $shown = $flags['clean'] && $tile['after'] ? $tile['after'] : $tile['before'];
                    @endphp
                    <li wire:key="tile-{{ $tile['index'] }}" @class(['relative rounded-xl border bg-white', 'border-2 border-brand-500' => $isMain, 'border-slate-200' => ! $isMain, 'opacity-50' => $removed]) data-tile="{{ $tile['index'] }}">
                        <div class="relative grid aspect-square place-items-center overflow-hidden rounded-t-xl bg-canvas">
                            @if ($shown)
                                <img src="{{ $shown }}" alt="{{ __($t.'.photos.photo', ['number' => $tile['index'] + 1]) }}" class="size-full object-contain">
                            @else
                                <span class="px-2 text-center text-xs text-slate-500">{{ __('admin.image_upload.processing') }}</span>
                            @endif
                            <span class="absolute left-2 top-2 rounded-md bg-white/90 px-1.5 py-0.5 text-xs font-semibold text-slate-700">
                                {{ $flags['source'] === 'upload' ? __($t.'.photos.new') : __($t.'.photos.existing') }} {{ $tile['index'] + 1 }}
                            </span>
                            @if ($isMain)
                                <span class="absolute bottom-2 left-2 rounded-md bg-brand-600 px-1.5 py-0.5 text-xs font-semibold text-white">{{ __('admin.products.images.main') }}</span>
                            @endif
                        </div>
                        <div class="space-y-2 border-t border-slate-100 p-2 text-xs">
                            @if ($flags['clean'] && $tile['after'] && $tile['before'])
                                <div class="flex items-center gap-2" data-before-after>
                                    <img src="{{ $tile['before'] }}" alt="{{ __($t.'.photos.before') }}" class="size-10 rounded border border-slate-200 object-contain">
                                    <x-admin.icon name="chevron-right" size="size-3.5" class="text-slate-400" />
                                    <img src="{{ $tile['after'] }}" alt="{{ __($t.'.photos.after') }}" class="size-10 rounded border border-slate-200 object-contain">
                                </div>
                            @endif
                            @unless ($removed)
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" wire:click="toggleClean({{ $tile['index'] }})" @checked($flags['clean']) class="rounded border-slate-300 text-brand-600">
                                    <span>{{ __($t.'.photos.clean') }}</span>
                                </label>
                                <label class="flex items-center gap-2">
                                    <input type="radio" name="main-image" wire:click="setMain({{ $tile['index'] }})" @checked($isMain) class="border-slate-300 text-brand-600">
                                    <span>{{ __($t.'.photos.main') }}</span>
                                </label>
                                @if ($improve && $flags['source'] === 'upload')
                                    <label class="block">
                                        <span class="text-slate-600">{{ __($t.'.photos.replaces') }}</span>
                                        <select wire:change="setReplace({{ $tile['index'] }}, $event.target.value)" class="mt-1 h-9 w-full rounded-lg border border-slate-200 bg-white px-2 text-xs">
                                            <option value="">{{ __($t.'.photos.add_new') }}</option>
                                            @foreach ($tiles as $other)
                                                @if ($other['flags']['source'] === 'media' && $other['flags']['action'] !== 'remove')
                                                    <option value="{{ $other['index'] }}" @selected($flags['action'] === 'replace' && $flags['replaces'] === $other['index'])>{{ __($t.'.photos.existing') }} {{ $other['index'] + 1 }}</option>
                                                @endif
                                            @endforeach
                                        </select>
                                    </label>
                                @endif
                            @endunless
                            <button type="button" wire:click="toggleRemove({{ $tile['index'] }})" class="inline-flex h-8 items-center gap-1 rounded-lg px-2 font-semibold text-slate-600 hover:bg-slate-100" data-remove>
                                <x-admin.icon :name="$removed ? 'restore' : 'trash'" size="size-3.5" />{{ $removed ? __($t.'.photos.restore') : __($t.'.photos.remove') }}
                            </button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="flex flex-wrap justify-between gap-2">
            <x-admin.button variant="secondary" icon="chevron-left" wire:click="goTo('info')">{{ __($t.'.back') }}</x-admin.button>
            <x-admin.button icon="sparkles" wire:click="look" loading="look" :disabled="! $photoHint" data-look>{{ __($t.'.photos.look') }}</x-admin.button>
        </div>
    </div>
</x-admin.card>
