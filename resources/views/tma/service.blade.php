{{--
    "Service for this device" in the Mini App (ServiceRequestController): the device comes from the
    product (shown, not editable); the customer describes the problem, may add the serial number and
    picks how to be contacted. Name and phone are the bot registration's.
--}}
@extends('layouts.tma')

@section('content')
    <form method="POST" action="{{ route('tma.service.store', ['product' => $product->slug]) }}" class="tma-card grid gap-3" data-service-form>
        @csrf
        <div class="grid gap-0.5 text-sm" data-device>
            <span class="text-tg-hint">{{ __('tma.service.device') }}</span>
            <span class="font-medium">{{ trim(($device['brand'] ?? '').' '.$device['model']) }}</span>
        </div>

        <label class="grid gap-1 text-sm">
            <span class="font-medium">{{ __('tma.service.fields.message') }}</span>
            <textarea name="message" rows="4" minlength="10" maxlength="2000" required class="tma-input" placeholder="{{ __('tma.service.message_placeholder') }}">{{ old('message') }}</textarea>
        </label>

        <label class="grid gap-1 text-sm">
            <span class="font-medium">{{ __('tma.service.fields.serial_number') }}</span>
            <input type="text" name="serial_number" value="{{ old('serial_number') }}" maxlength="64" autocomplete="off" class="tma-input">
        </label>

        <fieldset class="grid gap-1.5 text-sm">
            <legend class="mb-1 font-medium">{{ __('tma.service.fields.preferred_contact') }}</legend>
            @foreach ($preferredContacts as $contact)
                <label class="flex items-center gap-2"><input type="radio" name="preferred_contact" value="{{ $contact->value }}" @checked(old('preferred_contact', 'telegram') === $contact->value) class="size-4"> {{ $contact->label() }}</label>
            @endforeach
        </fieldset>

        <button type="submit" class="tma-btn" data-main-button="{{ __('tma.service.submit') }}">{{ __('tma.service.submit') }}</button>
        <p class="text-xs text-tg-hint">{{ __('tma.request.note') }}</p>
    </form>
@endsection
