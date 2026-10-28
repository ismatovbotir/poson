@php
    /** @var \App\Support\Integrations\IntegrationField $field */
    $t = 'admin.settings.integrations';
    $model = 'form.values.'.$field->key;
    $id = $field->langKey();
    $hint = __($t.'.hints.'.$id);
    $hint = $state['locked'] ? __($t.'.locked_hint', ['env' => $field->env]) : $hint;
@endphp

{{-- One non-secret value. Fields locked by .env are shown read-only and never sent. --}}
<div wire:key="setting-{{ $id }}" data-setting="{{ $field->key }}" @class(['sm:col-span-2' => $field->type === \App\Support\Integrations\IntegrationField::TYPE_BOOL])>
    @if ($field->type === \App\Support\Integrations\IntegrationField::TYPE_BOOL)
        <x-admin.toggle wire:model="{{ $model }}" :label="$field->label()" :description="$hint" :disabled="$state['locked']" />
    @elseif ($field->type === \App\Support\Integrations\IntegrationField::TYPE_SELECT)
        <x-admin.select wire:model.live="{{ $model }}" :label="$field->label()" :hint="$hint" :disabled="$state['locked']">
            @foreach ($field->options as $option)
                <option value="{{ $option }}">{{ match ($field->key) {
                    'payme.mode' => \App\Enums\PaymeMode::from($option)->label(),
                    'telegram.locale' => __('admin.locale.'.$option),
                    default => $option,
                } }}</option>
            @endforeach
        </x-admin.select>
    @else
        <x-admin.input
            wire:model="{{ $model }}"
            :label="$field->label()"
            :hint="$hint"
            :disabled="$state['locked']"
            autocomplete="off"
            spellcheck="false"
            maxlength="64"
        >
            @if ($state['locked'])
                <x-slot:labelAside><x-admin.badge color="violet" class="ml-1"><x-admin.icon name="lock" size="size-3" />{{ __($t.'.status.locked') }}</x-admin.badge></x-slot:labelAside>
            @endif
        </x-admin.input>
    @endif
</div>
