{{-- AI wizard step "facts" (plan 32 §5); variables from AiWizard::render(). --}}
<x-admin.card :title="__($t.'.facts.title')" :description="__($t.'.facts.help')" data-step="facts">
    @if ($pending)
        <div wire:poll.2s="poll" class="flex flex-wrap items-center gap-3 rounded-xl bg-brand-50 p-4 text-sm text-brand-800" role="status" data-pending="seeing">
            <svg class="size-5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25" /><path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3" /></svg>
            <span class="min-w-0 flex-1">{{ __($t.'.facts.seeing') }}</span>
        </div>
    @elseif ($facts !== [])
        @php($unsure = (array) ($draft?->facts['unsure'] ?? []))
        <div class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach (['device_type', 'brand'] as $field)
                    <x-admin.input wire:model="facts.{{ $field }}" :label="__($t.'.facts.'.$field)" maxlength="{{ \App\Support\Content\AiProduct\AiFactsParser::LIMITS[$field][1] }}" autocomplete="off" :hint="in_array($field, $unsure, true) ? __($t.'.facts.unsure') : null" />
                @endforeach
                @foreach (\App\Livewire\Admin\Products\AiWizard::LIST_FACTS as $field)
                    <x-admin.textarea wire:model="facts.{{ $field }}" :label="__($t.'.facts.'.$field)" rows="3" :hint="in_array($field, $unsure, true) ? __($t.'.facts.unsure').' '.__($t.'.facts.one_per_line') : __($t.'.facts.one_per_line')" />
                @endforeach
            </div>

            @if ($factViews !== [])
                <div class="space-y-3">
                    <h3 class="text-sm font-semibold">{{ __($t.'.facts.views') }}</h3>
                    @foreach ($factViews as $view)
                        <div wire:key="view-{{ $view['number'] }}" class="rounded-xl border border-slate-200 p-3" data-view="{{ $view['number'] }}">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-semibold">{{ __($t.'.photos.photo', ['number' => ($view['index'] ?? 0) + 1]) }}</span>
                                @foreach ($view['warnings'] as $warning)
                                    <x-admin.badge color="amber" data-warning="{{ $warning }}">{{ __($t.'.facts.warnings.'.$warning) }}</x-admin.badge>
                                @endforeach
                                @if ($view['index'] !== null && ($images[$view['index']]['action'] ?? '') !== 'remove' && $view['warnings'] !== [])
                                    <button type="button" wire:click="toggleRemove({{ $view['index'] }})" class="ml-auto inline-flex h-8 items-center gap-1 rounded-lg px-2 text-xs font-semibold text-danger hover:bg-red-50" data-remove-view><x-admin.icon name="trash" size="size-3.5" />{{ __($t.'.photos.remove') }}</button>
                                @elseif ($view['index'] !== null && ($images[$view['index']]['action'] ?? '') === 'remove')
                                    <x-admin.badge color="muted" class="ml-auto">{{ __($t.'.facts.removed') }}</x-admin.badge>
                                @endif
                            </div>
                            <x-admin.input wire:model="facts.views.{{ $view['number'] }}" :label="__($t.'.facts.view_note')" maxlength="{{ \App\Support\Content\AiProduct\AiFactsParser::NOTE_MAX }}" class="mt-2" autocomplete="off" />
                        </div>
                    @endforeach
                </div>
            @endif

            <x-admin.field-error name="facts" />
            <p class="text-xs text-slate-500">{{ __($t.'.facts.confirm_hint') }}</p>
            <div class="flex flex-wrap justify-between gap-2">
                <x-admin.button variant="secondary" icon="chevron-left" wire:click="goTo('photos')">{{ __($t.'.back') }}</x-admin.button>
                <x-admin.button icon="sparkles" wire:click="write" loading="write" data-write>{{ __($t.'.facts.confirm') }}</x-admin.button>
            </div>
        </div>
    @else
        <div class="flex justify-start">
            <x-admin.button variant="secondary" icon="chevron-left" wire:click="goTo('photos')">{{ __($t.'.back') }}</x-admin.button>
        </div>
    @endif
</x-admin.card>
