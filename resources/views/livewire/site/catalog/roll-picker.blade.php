{{--
    Roll picker of a label product (App\Livewire\Site\Catalog\RollPicker, plan 08).
    Retail price only; without a price no amounts are shown (quote only). No discount wording.
--}}
<div class="mt-5 grid gap-4" data-roll-picker>
    @if ($rollTypes->isNotEmpty())
        <fieldset>
            <legend class="mb-2 text-sm font-semibold">{{ __('site.roll_picker.title') }}</legend>
            <div class="grid gap-2">
                @foreach ($rollTypes as $rollType)
                    <label wire:key="roll-type-{{ $rollType->id }}" class="flex cursor-pointer items-center gap-3 rounded-btn border-[1.5px] border-line px-3.5 py-2.5 text-sm transition-colors hover:border-brand has-checked:border-brand-600 has-checked:bg-brand-50">
                        <input type="radio" wire:model.live="rollTypeId" value="{{ $rollType->id }}" class="size-4 accent-brand-600" data-roll-type-option="{{ $rollType->id }}">
                        <span class="font-medium">{{ $rollType->label() }}</span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        <div>
            <label for="roll-picker-rolls" class="mb-2 block text-sm font-semibold">{{ __('site.roll_picker.rolls') }}</label>
            <div class="flex items-center gap-2">
                <button type="button" wire:click="decrement" @disabled($rolls <= $minRolls) class="grid size-11 place-items-center rounded-btn border-[1.5px] border-line text-lg font-semibold transition-colors hover:border-brand disabled:opacity-40" aria-label="{{ __('site.roll_picker.decrease') }}">−</button>
                <input id="roll-picker-rolls" type="number" wire:model.live.debounce.400ms="rolls" min="{{ $minRolls }}" max="{{ $maxRolls }}" step="1" inputmode="numeric" class="h-11 w-24 rounded-btn border-[1.5px] border-line bg-white px-3 text-center font-semibold tabular-nums focus:border-brand-600 focus:outline-none" aria-describedby="roll-picker-min" data-rolls>
                <button type="button" wire:click="increment" @disabled($rolls >= $maxRolls) class="grid size-11 place-items-center rounded-btn border-[1.5px] border-line text-lg font-semibold transition-colors hover:border-brand disabled:opacity-40" aria-label="{{ __('site.roll_picker.increase') }}">+</button>
            </div>
            <p id="roll-picker-min" class="mt-1.5 text-[13px] text-ink-2" data-min-rolls>{{ trans_choice('site.roll_picker.min_hint', $minRolls, ['count' => \App\Support\NumberFormat::format($minRolls)]) }}</p>
        </div>

        <div aria-live="polite">
            @if ($line?->hasPrice())
                <dl class="grid gap-1.5 rounded-btn bg-bg-soft px-4 py-3 text-sm" data-roll-amounts>
                    <div class="flex justify-between gap-3"><dt class="text-ink-2">{{ __('site.roll_picker.per_roll') }}</dt><dd class="font-semibold tabular-nums" data-roll-price>{{ \App\Support\Money::format($line->rollPrice) }}</dd></div>
                    <div class="flex justify-between gap-3 text-base"><dt class="font-semibold">{{ __('site.roll_picker.total') }}</dt><dd class="font-extrabold tabular-nums" data-roll-total>{{ \App\Support\Money::format($line->total) }}</dd></div>
                </dl>
            @else
                <p class="rounded-btn bg-bg-soft px-4 py-3 text-sm text-ink-2" data-roll-on-request>{{ __('site.roll_picker.on_request') }}</p>
            @endif
        </div>
    @else
        <p class="rounded-btn bg-bg-soft px-4 py-3 text-sm text-ink-2" data-roll-none>{{ __('site.roll_picker.none') }}</p>
    @endif
</div>
