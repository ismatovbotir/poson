{{--
    Searchable option picker of a select / multiselect spec. Picking an
    option is a server update (the label and chips come from the server);
    Alpine only keeps the dropdown open and filters the list. "New value"
    creates an option (attributes.update) and selects it.
--}}
@php
    $multiple = $type === \App\Enums\AttributeType::Multiselect;
    $options = $attribute->options;
    $chosen = $multiple ? array_map('intval', (array) $row['options']) : array_filter([(int) $row['option']]);
    $chosenOptions = $options->filter(fn ($option) => in_array($option->id, $chosen, true));
    $path = $multiple ? "form.specs.{$index}.options" : "form.specs.{$index}.option";
@endphp

<div
    class="relative"
    x-data="{ open: false, q: '', matches(label) { return label.toLowerCase().includes(this.q.trim().toLowerCase()) } }"
    x-on:click.outside="open = false; q = ''"
    x-on:keydown.escape.stop="open = false"
    data-option-picker="{{ $attribute->code }}"
>
    <button
        type="button"
        x-on:click="open = !open; $nextTick(() => open && $refs.q.focus())"
        x-bind:aria-expanded="open.toString()"
        aria-haspopup="listbox"
        aria-label="{{ $attribute->name }}"
        @class(['flex min-h-10 w-full flex-wrap items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-left text-sm hover:border-slate-300', 'border-red-300 bg-red-50/40' => $flagged || $hasError, 'border-slate-200 bg-white' => ! ($flagged || $hasError)])
    >
        @if ($multiple)
            @forelse ($chosenOptions as $option)
                <span wire:key="chip-{{ $attribute->id }}-{{ $option->id }}" class="inline-flex items-center gap-1 rounded-md bg-brand-50 py-0.5 pl-2 pr-1 text-xs font-semibold text-brand-700">
                    {{ $option->value }}
                    <span role="button" tabindex="0" wire:click.stop="toggleOption({{ $attribute->id }}, {{ $option->id }})" x-on:keydown.enter.stop.prevent="$wire.toggleOption({{ $attribute->id }}, {{ $option->id }})" class="grid size-4 place-items-center rounded hover:bg-brand-100" aria-label="{{ __('admin.products.specs.remove_value') }}: {{ $option->value }}"><x-admin.icon name="x" size="size-3" /></span>
                </span>
            @empty
                <span class="px-0.5 text-slate-400">{{ __('admin.products.specs.choose_many') }}</span>
            @endforelse
        @else
            <span @class(['px-0.5', 'text-slate-900' => $chosenOptions->isNotEmpty(), 'text-slate-400' => $chosenOptions->isEmpty()])>{{ $chosenOptions->first()?->value ?? __('admin.common.choose') }}</span>
        @endif
        <x-admin.icon name="chevron-down" size="size-4" class="ml-auto shrink-0 text-slate-400" />
    </button>

    <div x-cloak x-show="open" x-transition.origin.top class="absolute inset-x-0 z-30 mt-1 rounded-xl border border-slate-200 bg-white p-1.5 shadow-xl shadow-slate-900/10">
        <div class="relative">
            <x-admin.icon name="search" size="size-4" class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400" />
            <input x-ref="q" x-model="q" x-on:keydown.enter.prevent type="search" placeholder="{{ __('admin.products.specs.search_value') }}" aria-label="{{ __('admin.products.specs.search_value') }}" class="h-9 w-full rounded-lg bg-slate-50 pl-8 pr-2 text-base outline-none focus:bg-white focus:ring-2 focus:ring-brand-500/30 sm:text-sm">
        </div>
        <ul class="mt-1 max-h-56 overflow-y-auto" role="listbox" @if ($multiple) aria-multiselectable="true" @endif>
            @if (! $multiple && $chosenOptions->isNotEmpty())
                <li>
                    <button type="button" wire:click="selectOption({{ $attribute->id }}, 0)" x-on:click="open = false; q = ''" class="flex w-full items-center rounded-lg px-2.5 py-2 text-left text-sm text-slate-500 hover:bg-slate-50">{{ __('admin.products.specs.clear_value') }}</button>
                </li>
            @endif
            @foreach ($options as $option)
                @php($selected = in_array($option->id, $chosen, true))
                <li wire:key="opt-{{ $attribute->id }}-{{ $option->id }}" x-show="matches(@js((string) $option->value))">
                    <button
                        type="button"
                        role="option"
                        aria-selected="{{ $selected ? 'true' : 'false' }}"
                        @if ($multiple)
                            wire:click="toggleOption({{ $attribute->id }}, {{ $option->id }})"
                        @else
                            wire:click="selectOption({{ $attribute->id }}, {{ $option->id }})"
                            x-on:click="open = false; q = ''"
                        @endif
                        class="flex w-full items-center justify-between rounded-lg px-2.5 py-2 text-left text-sm hover:bg-slate-50"
                    >
                        <span>{{ $option->value }}</span>
                        @if ($selected)<x-admin.icon name="check" size="size-4" class="text-brand-600" />@endif
                    </button>
                </li>
            @endforeach
            @if ($options->isEmpty())
                <li class="px-2.5 py-3 text-center text-sm text-slate-400">{{ __('admin.attributes.options.none') }}</li>
            @endif
        </ul>
        @if ($canCreateOptions)
            <button
                type="button"
                x-show="q.trim() && ! @js($options->map(fn ($option) => mb_strtolower((string) $option->value))->values()).includes(q.trim().toLowerCase())"
                x-on:click="$wire.createOption({{ $attribute->id }}, q.trim()); q = ''; @if (! $multiple) open = false @endif"
                class="mt-1 flex w-full items-center gap-2 rounded-lg border-t border-slate-100 px-2.5 py-2 text-left text-sm font-semibold text-brand-700 hover:bg-brand-50"
                data-new-option
            >
                <x-admin.icon name="plus" size="size-4" />{{ __('admin.products.specs.new_value') }}: “<span x-text="q.trim()"></span>”
            </button>
        @endif
    </div>
</div>
