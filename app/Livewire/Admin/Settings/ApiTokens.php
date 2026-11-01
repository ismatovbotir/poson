<?php

namespace App\Livewire\Admin\Settings;

use App\Actions\Api\CreateApiToken;
use App\Actions\Api\RevokeApiToken;
use App\Enums\ApiTokenType;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Models\ApiToken;
use App\Support\Admin\PasswordConfirmation;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Settings → API tokens (/admin/settings/api-tokens, plan 09): tokens of
 * the price API (1C). api-tokens.manage (Admin only), authorized in boot()
 * and again before each write; creating and revoking need a password
 * confirmation from the last 15 minutes. Only price API tokens (type
 * price_api) are listed or revocable here (plan 13, C5).
 *
 * The plain token is shown once: it lives only in the protected
 * $revealedToken of the request that created it (rendered into that one
 * response), never in a public property or the Livewire snapshot.
 */
#[Layout('layouts::admin')]
class ApiTokens extends Component
{
    use HandlesActionErrors;

    public string $name = '';

    /**
     * Days until expiry ('' = never).
     */
    public string $expiresInDays = '365';

    /**
     * Plain value of the token created in this request (shown once).
     */
    protected ?string $revealedToken = null;

    protected ?string $revealedName = null;

    /**
     * Runs on every request (initial render and each update).
     */
    public function boot(): void
    {
        $this->authorize('viewAny', ApiToken::class);
    }

    public function create(CreateApiToken $createToken): void
    {
        $this->authorize('create', ApiToken::class);

        if (! $this->ensurePasswordConfirmed()) {
            return;
        }

        [$token, $plain] = $this->runFormAction(fn (): array => $createToken->handle([
            'name' => $this->name,
            'expires_in_days' => ctype_digit($this->expiresInDays) ? (int) $this->expiresInDays : null,
        ], auth()->user(), request()->ip()), '');

        $this->revealedToken = $plain;
        $this->revealedName = $token->name;
        $this->reset('name', 'expiresInDays');
        $this->dispatch('api-token-created');
    }

    public function revoke(int $id, RevokeApiToken $revokeToken): void
    {
        $token = ApiToken::query()->ofType(ApiTokenType::PriceApi)->findOrFail($id);

        $this->authorize('revoke', $token);

        if (! $this->ensurePasswordConfirmed()) {
            return;
        }

        if (! $revokeToken->handle($token, auth()->user(), request()->ip())) {
            $this->dispatch('toast', title: __('admin.settings.integrations.no_changes'), type: 'info');

            return;
        }

        $this->dispatch('toast', title: __('admin.toast.done'), body: __('api.tokens.revoked', ['name' => $token->name]), type: 'success');
    }

    /**
     * Send the user to the password confirmation and back here.
     */
    public function confirmPassword(): void
    {
        $this->redirect(PasswordConfirmation::url(route('admin.settings.api-tokens')), navigate: false);
    }

    public function render(): View
    {
        return view('livewire.admin.settings.api-tokens', [
            'tokens' => ApiToken::query()->ofType(ApiTokenType::PriceApi)->with('creator:id,name')->latest('id')->get(),
            'revealedToken' => $this->revealedToken,
            'revealedName' => $this->revealedName,
            'passwordConfirmed' => PasswordConfirmation::isRecent(),
            'expiryOptions' => collect(CreateApiToken::EXPIRY_DAYS)->mapWithKeys(fn (?int $days): array => [
                (string) $days => __('api.tokens.expiry.'.($days ?? 'never')),
            ])->all(),
            'endpoint' => rtrim((string) config('app.url'), '/').route('api.v1.prices', absolute: false),
        ])->title(__('api.tokens.title'));
    }

    /**
     * True when the password was confirmed recently; otherwise redirects to
     * the confirmation page (and back here afterwards).
     */
    protected function ensurePasswordConfirmed(): bool
    {
        if (PasswordConfirmation::isRecent()) {
            return true;
        }

        $this->confirmPassword();

        return false;
    }
}
