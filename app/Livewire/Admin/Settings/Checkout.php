<?php

namespace App\Livewire\Admin\Settings;

use App\Actions\Settings\UpdateCheckoutSettings;
use App\Actions\Settings\UpdateUzqrImage;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Forms\Admin\Settings\CheckoutForm;
use App\Models\Setting;
use App\Support\Admin\PasswordConfirmation;
use App\Support\CompanySettings;
use App\Support\Orders\CheckoutSettings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Settings → Checkout (/admin/settings/checkout, plan 11b): settings.checkout
 * (Admin only), authorized on every request (boot) and before saving.
 * Values are written through UpdateCheckoutSettings (area Checkout, audited
 * without values); the static UzQR code through UpdateUzqrImage
 * (re-encoded PNG, previous code kept, audited, finance + sales alert).
 * Changing the UzQR code or its recipient needs a password confirmation
 * from the last 15 minutes (security review M2).
 * The current code is previewed as a data URI (never a URL).
 */
#[Layout('layouts::admin')]
class Checkout extends Component
{
    use HandlesActionErrors, WithFileUploads;

    public CheckoutForm $form;

    public function boot(): void
    {
        $this->authorize('manageCheckout', Setting::class);
    }

    public function mount(CheckoutSettings $settings): void
    {
        $this->form->fillFrom($settings);
    }

    public function updatedFormUzqr(): void
    {
        $this->validateOnly('form.uzqr');
    }

    public function removeUzqr(): void
    {
        if ($this->form->uzqr !== null) {
            $this->form->uzqr = null;

            return;
        }

        $this->form->remove_uzqr = true;
    }

    public function save(UpdateCheckoutSettings $updateSettings, UpdateUzqrImage $updateUzqr, CheckoutSettings $settings): void
    {
        $this->authorize('manageCheckout', Setting::class);

        $this->form->validate();

        $user = auth()->user();
        $ip = request()->ip();
        $file = $this->form->uzqr;
        $values = $this->form->values();

        // Where customers send money (the UzQR code and its recipient) needs a
        // fresh password (security review M2); fees, caps and codes don't.
        if (($file !== null || $this->form->remove_uzqr || $updateSettings->changesRecipient($values)) && ! $this->ensurePasswordConfirmed()) {
            return;
        }

        // The image is checked (and re-encoded) before anything is written.
        if ($file !== null) {
            $this->runFormAction(fn () => @unlink($updateUzqr->reencode($file)), 'form.');
        }

        $changed = $updateSettings->handle($values, $user, $ip);
        $changed = $this->runFormAction(fn (): bool => $updateUzqr->handle($file, $this->form->remove_uzqr, $user, $ip), 'form.') || $changed;

        CompanySettings::flush();
        $this->form->fillFrom($settings);

        $this->dispatch('toast', title: $changed ? __('admin.toast.saved') : __('admin.settings.company.no_changes'), type: $changed ? 'success' : 'info');
    }

    /**
     * Send the user to the password confirmation and back here.
     */
    public function confirmPassword(): void
    {
        $this->redirect(PasswordConfirmation::url(route('admin.settings.checkout')), navigate: false);
    }

    protected function ensurePasswordConfirmed(): bool
    {
        if (PasswordConfirmation::isRecent()) {
            return true;
        }

        $this->confirmPassword();

        return false;
    }

    public function render(CheckoutSettings $settings): View
    {
        return view('livewire.admin.settings.checkout', [
            'uzqrPreview' => $this->form->remove_uzqr ? null : $settings->uzqrDataUri(),
            'passwordConfirmed' => PasswordConfirmation::isRecent(),
        ])->title(__('checkout.settings.title'));
    }
}
