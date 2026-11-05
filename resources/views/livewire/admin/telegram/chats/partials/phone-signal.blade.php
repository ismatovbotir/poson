{{-- Server-computed phone check (T21-36). Shows the result only, never the customer's phone. --}}
<p data-phone-signal="{{ $signal }}">
    <x-admin.badge :color="$signal === \App\Support\Telegram\Bot\LinkPhoneSignal::MATCHES ? 'brand' : 'amber'">{{ __('telegram_inbox.crm.phone_signal.'.\App\Support\Telegram\Bot\LinkPhoneSignal::labelKey($signal, $contactChosen)) }}</x-admin.badge>
</p>
