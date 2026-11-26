{{--
    Cart line stepper (plan 45 §3): − [n] + as two submit buttons of one form posting qty = n ± 1 to
    tma.cart.update; at the minimum the minus button posts to tma.cart.remove (formaction). Step 1,
    floor $min, ceiling Cart::MAX_QTY; the server (UpdateCartLine) decides again. tma.js sends it with
    fetch when $json; otherwise PRG back to "from" (TmaReturn). Hidden while $qty is 0.
    Vars: $lineKey, $qty, $min, $from (nullable), $json (bool), $label (accessible name of the line).
--}}
@php
    $removeUrl = route('tma.cart.remove', ['lineKey' => $lineKey]);
    $max = \App\Models\Cart::MAX_QTY;
@endphp
<form method="POST" action="{{ route('tma.cart.update', ['lineKey' => $lineKey]) }}" class="tma-stepper" role="group" @if ($json) data-cart-form="update" @endif data-min="{{ $min }}" data-max="{{ $max }}" @if ($qty < 1) hidden @endif aria-label="{{ $label }}">
    @csrf
    @if ($from !== null)
        <input type="hidden" name="from" value="{{ $from }}">
    @endif
    <button type="submit" name="qty" value="{{ max(0, $qty - 1) }}" data-step="-1" data-remove="{{ $removeUrl }}" @if ($qty <= $min) formaction="{{ $removeUrl }}" @endif aria-label="{{ __('tma.stepper.less') }}"><x-tma.icon name="minus" /></button>
    <output data-qty aria-live="polite">{{ $qty }}</output>
    <button type="submit" name="qty" value="{{ max($qty + 1, $min) }}" data-step="1" @disabled($qty >= $max) aria-label="{{ __('tma.stepper.more') }}"><x-tma.icon name="plus" /></button>
</form>
