<?php

namespace App\Actions\Settings;

use App\Enums\SettingsArea;
use App\Events\UzqrImageChanged;
use App\Models\User;
use App\Support\Orders\CheckoutSettings;
use Illuminate\Support\Facades\DB;

/**
 * Save Settings → Checkout values (plan 11b) through UpdateCompanySettings
 * (area Checkout, audited without values). A changed UzQR recipient name is
 * a payment-destination change (security review M2): it alerts finance and
 * sales (UzqrImageChanged "recipient_changed"). The caller requires a fresh
 * password confirmation for it.
 */
class UpdateCheckoutSettings
{
    public function __construct(protected UpdateCompanySettings $updateSettings, protected CheckoutSettings $settings) {}

    /**
     * Whether saving these values changes the UzQR recipient.
     *
     * @param  array<string, mixed>  $values
     */
    public function changesRecipient(array $values): bool
    {
        return trim((string) ($values['uzqr_recipient'] ?? '')) !== $this->settings->uzqrRecipient();
    }

    /**
     * @param  array<string, mixed>  $values  the "checkout" settings value
     * @return bool whether anything changed
     */
    public function handle(array $values, ?User $user, ?string $ip): bool
    {
        return DB::transaction(function () use ($values, $user, $ip): bool {
            $recipientChanged = $this->changesRecipient($values);
            $changed = $this->updateSettings->handle(SettingsArea::Checkout, ['checkout' => $values], $user, $ip) !== [];

            if ($changed && $recipientChanged) {
                UzqrImageChanged::dispatch('recipient_changed', $user);
            }

            return $changed;
        });
    }
}
