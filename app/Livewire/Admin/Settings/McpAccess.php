<?php

namespace App\Livewire\Admin\Settings;

use App\Actions\Mcp\GenerateMcpToken;
use App\Actions\Mcp\GrantMcpAccess;
use App\Actions\Mcp\RevokeAllMcpAccess;
use App\Actions\Mcp\RevokeMcpAccess;
use App\Actions\Mcp\RevokeMcpToken;
use App\Actions\Mcp\UpdateMcpTokenSettings;
use App\Enums\ApiTokenType;
use App\Enums\McpCallStatus;
use App\Enums\McpRevokeReason;
use App\Enums\Role;
use App\Models\ApiToken;
use App\Models\McpCall;
use App\Models\User;
use App\Support\Admin\PasswordConfirmation;
use App\Support\Mcp\McpAlerts;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Settings → MCP access (/admin/settings/mcp, plan 13 §7, C4, C9, C22, C26,
 * C28 and the single-holder scope update): the CEO's own Claude connection.
 *
 * - mcp.manage (Admin only), authorized in boot() and again before each
 *   write; every write also needs a password confirmation from the last 15
 *   minutes. Targets (user, token, call) arrive as ids and are re-loaded and
 *   re-checked by the actions; editingTokenId / viewingCallId are Locked.
 * - Holder: grant to an active Admin, transfer (revokes the previous
 *   holder's tokens first), revoke, revoke all.
 * - Tokens: up to 3 labelled tokens, generate / regenerate / revoke and
 *   per-token idle days + IP allow-list.
 * - The plain token is shown once: it lives only in the protected
 *   $revealedToken of the request that generated it, never in a public
 *   property or the Livewire snapshot.
 * - Call log tab: mcp_calls with filters and the before-snapshot of writes.
 */
#[Layout('layouts::admin')]
class McpAccess extends Component
{
    use WithPagination;

    /**
     * Version of mcp-remote shown in the Claude Desktop config (C15: pinned, ≥ 0.1.16).
     */
    public const MCP_REMOTE_VERSION = '0.1.16';

    #[Url]
    public string $tab = 'access';

    public ?int $grantUserId = null;

    public string $label = '';

    public string $idleRevokeDays = '';

    public string $allowedIps = '';

    #[Locked]
    public ?int $editingTokenId = null;

    public string $editIdleRevokeDays = '';

    public string $editAllowedIps = '';

    #[Locked]
    public ?int $viewingCallId = null;

    #[Url(as: 'tool')]
    public string $callTool = '';

    #[Url(as: 'status')]
    public string $callStatus = '';

    #[Url(as: 'writes')]
    public bool $writesOnly = false;

    /**
     * Plain value of the token generated in this request (shown once).
     */
    protected ?string $revealedToken = null;

    protected ?string $revealedLabel = null;

    /**
     * Runs on every request (initial render and each update).
     */
    public function boot(): void
    {
        $this->authorize('viewAny', McpCall::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['callTool', 'callStatus', 'writesOnly'], true)) {
            $this->resetPage();
        }
    }

    public function grant(GrantMcpAccess $grant): void
    {
        $this->grantTo($grant, transfer: false);
    }

    public function transfer(GrantMcpAccess $grant): void
    {
        $this->grantTo($grant, transfer: true);
    }

    public function revokeAccess(RevokeMcpAccess $revoke): void
    {
        if (! $this->authorizeWrite()) {
            return;
        }

        $holder = User::query()->whereNotNull('mcp_access_granted_at')->first();

        if ($holder === null) {
            $this->dispatch('toast', title: __('admin.settings.integrations.no_changes'), type: 'info');

            return;
        }

        $count = $revoke->handle($holder, McpRevokeReason::Admin, auth()->user(), request()->ip());

        $this->dispatch('toast', title: __('admin.toast.done'), body: __('mcp.holder.revoked_toast', ['count' => $count]), type: 'success');
    }

    public function revokeAll(RevokeAllMcpAccess $revokeAll): void
    {
        if (! $this->authorizeWrite()) {
            return;
        }

        $count = $revokeAll->handle(auth()->user(), request()->ip());

        $this->dispatch('toast', title: __('admin.toast.done'), body: __('mcp.holder.revoked_toast', ['count' => $count]), type: 'success');
    }

    public function generate(GenerateMcpToken $generate): void
    {
        if (! $this->authorizeWrite()) {
            return;
        }

        $holder = $this->holder();

        if ($holder === null) {
            $this->addError('label', __('mcp.errors.not_holder'));

            return;
        }

        $result = $this->runAction(fn (): array => $generate->handle($holder, [
            'label' => $this->label,
            'idle_revoke_days' => $this->idleRevokeDays,
            'allowed_ips' => $this->allowedIps,
        ], auth()->user(), request()->ip()), '');

        if ($result === null) {
            return;
        }

        $this->reveal(...$result);
        $this->reset('label', 'idleRevokeDays', 'allowedIps');
        $this->dispatch('mcp-token-generated');
    }

    /**
     * New token for the same label and settings; the old one is revoked.
     */
    public function regenerate(int $tokenId, GenerateMcpToken $generate): void
    {
        if (! $this->authorizeWrite()) {
            return;
        }

        $token = $this->activeToken($tokenId);

        $result = $this->runAction(fn (): array => $generate->handle($token->user, [
            'label' => $token->name,
            'idle_revoke_days' => $token->idle_revoke_days,
            'allowed_ips' => $token->allowed_ips ?? [],
        ], auth()->user(), request()->ip()));

        if ($result !== null) {
            $this->reveal(...$result);
        }
    }

    public function revokeToken(int $tokenId, RevokeMcpToken $revoke): void
    {
        if (! $this->authorizeWrite()) {
            return;
        }

        $token = $this->activeToken($tokenId);

        $revoke->handle($token, McpRevokeReason::Admin, auth()->user(), request()->ip());

        $this->dispatch('toast', title: __('admin.toast.done'), body: __('mcp.tokens.revoked_toast', ['name' => $token->name]), type: 'success');
    }

    public function editToken(int $tokenId): void
    {
        $this->authorize('manage', McpCall::class);

        $token = $this->activeToken($tokenId);

        $this->editingTokenId = $token->id;
        $this->editIdleRevokeDays = $token->idle_revoke_days !== null ? (string) $token->idle_revoke_days : '';
        $this->editAllowedIps = implode("\n", $token->allowed_ips ?? []);
        $this->resetValidation();
    }

    public function saveTokenSettings(UpdateMcpTokenSettings $update): void
    {
        if (! $this->authorizeWrite() || $this->editingTokenId === null) {
            return;
        }

        $token = $this->activeToken($this->editingTokenId);

        $changed = $this->runAction(fn (): bool => $update->handle($token, [
            'idle_revoke_days' => $this->editIdleRevokeDays,
            'allowed_ips' => $this->editAllowedIps,
        ], auth()->user(), request()->ip()), 'edit_');

        if ($changed === null) {
            return;
        }

        $this->editingTokenId = null;
        $this->dispatch('toast', title: $changed ? __('admin.toast.done') : __('admin.settings.integrations.no_changes'), body: $changed ? __('mcp.tokens.settings_saved') : null, type: $changed ? 'success' : 'info');
    }

    public function cancelEdit(): void
    {
        $this->editingTokenId = null;
        $this->resetValidation();
    }

    public function showBefore(int $callId): void
    {
        $call = McpCall::query()->whereNotNull('before')->findOrFail($callId);

        $this->authorize('view', $call);

        $this->viewingCallId = $call->id;
    }

    public function closeBefore(): void
    {
        $this->viewingCallId = null;
    }

    /**
     * Send the user to the password confirmation and back here.
     */
    public function confirmPassword(): void
    {
        $this->redirect(PasswordConfirmation::url(route('admin.settings.mcp')), navigate: false);
    }

    public function render(): View
    {
        $holder = $this->holder();
        $holder?->load('mcpAccessGrantedBy');

        $activeTokens = $holder === null ? collect() : ApiToken::query()->ofType(ApiTokenType::Mcp)->where('user_id', $holder->id)
            ->whereNull('revoked_at')->oldest('id')->limit(GenerateMcpToken::MAX_ACTIVE)->get();
        $revokedTokens = $holder === null ? collect() : ApiToken::query()->ofType(ApiTokenType::Mcp)->where('user_id', $holder->id)
            ->whereNotNull('revoked_at')->latest('revoked_at')->latest('id')->limit(5)->get();
        $endpoint = rtrim((string) config('app.url'), '/').route('mcp.admin', absolute: false);

        return view('livewire.admin.settings.mcp-access', [
            'holder' => $holder,
            'admins' => User::query()->role(Role::Admin->value)->where('is_active', true)->orderBy('name')->get(),
            'activeTokens' => $activeTokens,
            'revokedTokens' => $revokedTokens,
            'maxTokens' => GenerateMcpToken::MAX_ACTIVE,
            'calls' => $this->tab === 'calls' ? $this->callsQuery()->paginate(25) : null,
            'callTools' => $this->tab === 'calls' ? McpCall::query()->distinct()->orderBy('tool')->pluck('tool')->all() : [],
            'callsLast7Days' => McpCall::query()->where('created_at', '>=', now()->subDays(7))->count(),
            'viewingCall' => $this->viewingCallId ? McpCall::query()->whereNotNull('before')->find($this->viewingCallId) : null,
            'editingToken' => $this->editingTokenId ? $activeTokens->firstWhere('id', $this->editingTokenId) : null,
            'revealedToken' => $this->revealedToken,
            'revealedLabel' => $this->revealedLabel,
            'endpoint' => $endpoint,
            'mcpRemoteVersion' => self::MCP_REMOTE_VERSION,
            'passwordConfirmed' => PasswordConfirmation::isRecent(),
            'managementConfigured' => McpAlerts::isConfigured(),
            'isProduction' => app()->isProduction(),
        ])->title(__('mcp.title'));
    }

    /**
     * @return Builder<McpCall>
     */
    protected function callsQuery(): Builder
    {
        return McpCall::query()
            ->with(['user:id,name', 'apiToken:id,name'])
            ->when($this->callTool !== '', fn (Builder $query) => $query->where('tool', $this->callTool))
            ->when(McpCallStatus::tryFrom($this->callStatus), fn (Builder $query, McpCallStatus $status) => $query->where('status', $status))
            ->when($this->writesOnly, fn (Builder $query) => $query->where('is_write', true))
            ->latest('id');
    }

    protected function grantTo(GrantMcpAccess $grant, bool $transfer): void
    {
        if (! $this->authorizeWrite()) {
            return;
        }

        $target = User::query()->find($this->grantUserId);

        if ($target === null) {
            $this->addError('user', __('mcp.errors.target_not_admin'));

            return;
        }

        $changed = $this->runAction(fn (): bool => $grant->handle($target, auth()->user(), request()->ip(), $transfer), '');

        if ($changed === null) {
            return;
        }

        $this->reset('grantUserId');
        $this->dispatch('toast', title: $changed ? __('admin.toast.done') : __('admin.settings.integrations.no_changes'), body: $changed ? __('mcp.holder.granted_toast', ['name' => $target->name]) : null, type: $changed ? 'success' : 'info');
    }

    /**
     * Run an action; validation errors go to the form fields ($prefix + key,
     * list items like allowed_ips.2 folded into allowed_ips) or, with a null
     * prefix, to a toast. Returns null when the action was refused.
     */
    protected function runAction(callable $callback, ?string $prefix = null): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            if ($prefix === null) {
                $this->dispatch('toast', title: __('admin.toast.error'), body: collect($exception->errors())->flatten()->first(), type: 'error');

                return null;
            }

            foreach ($exception->errors() as $key => $messages) {
                $this->addError($prefix.preg_replace('/\.\d+$/', '', $key), $messages[0]);
            }

            return null;
        }
    }

    protected function holder(): ?User
    {
        return User::query()->whereNotNull('mcp_access_granted_at')->oldest('mcp_access_granted_at')->first();
    }

    /**
     * An active MCP token by id (404 for price API tokens or revoked ones).
     */
    protected function activeToken(int $id): ApiToken
    {
        return ApiToken::query()->ofType(ApiTokenType::Mcp)->whereNull('revoked_at')->with('user')->findOrFail($id);
    }

    protected function reveal(ApiToken $token, string $plain): void
    {
        $this->revealedToken = $plain;
        $this->revealedLabel = $token->name;
    }

    /**
     * mcp.manage and a recent password confirmation (otherwise redirects to it).
     */
    protected function authorizeWrite(): bool
    {
        $this->authorize('manage', McpCall::class);

        if (PasswordConfirmation::isRecent()) {
            return true;
        }

        $this->confirmPassword();

        return false;
    }
}
