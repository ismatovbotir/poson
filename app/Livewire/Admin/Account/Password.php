<?php

namespace App\Livewire\Admin\Account;

use App\Actions\Users\ChangeOwnPassword;
use App\Http\Middleware\EnsureStaff;
use App\Livewire\Admin\Concerns\ChecksStaffPassword;
use App\Models\User;
use App\Rules\StaffPassword;
use App\Support\Admin\PasswordConfirmation;
use App\Support\Admin\StaffSession;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Change your own password (/admin/account/password). Open to every staff
 * role, and the only admin screen a user with a temporary password can reach.
 *
 * The current password is checked first, under the same limits as
 * ConfirmPassword. The new password's rules run only after that, because
 * "differs from current" would otherwise be an unthrottled password oracle.
 * On success this session stays, every other session and remember cookie
 * ends and the user's MCP tokens are revoked (the screen warns first).
 */
#[Layout('layouts::admin-guest')]
class Password extends Component
{
    use ChecksStaffPassword;

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    /**
     * @throws ValidationException
     */
    public function save(ChangeOwnPassword $changeOwnPassword): void
    {
        /** @var User $user */
        $user = auth()->user();
        $attributes = [
            'currentPassword' => __('staff.account.current_password'),
            'newPassword' => __('staff.account.new_password'),
            'newPasswordConfirmation' => __('staff.account.confirm_new_password'),
        ];

        try {
            $this->validate([
                'currentPassword' => ['required', 'string', 'max:255'],
                'newPassword' => ['required', 'string', 'max:255'],
                'newPasswordConfirmation' => ['required', 'string', 'max:255'],
            ], [], $attributes);

            if (! $this->checkStaffPassword($user, $this->currentPassword, 'currentPassword', allowDevLogin: false)) {
                return;
            }

            $this->validate([
                'newPassword' => [new StaffPassword($user)],
                'newPasswordConfirmation' => ['same:newPassword'],
            ], [], $attributes);

            $saved = $changeOwnPassword->handle($user, StaffSession::epoch(), $this->currentPassword, $this->newPassword, request()->ip());
        } finally {
            $this->reset('currentPassword', 'newPassword', 'newPasswordConfirmation');
        }

        if ($saved === null) {
            StaffSession::endCurrentDevice();
            session()->flash(EnsureStaff::NOTICE_KEY, __('admin.auth.session_ended'));

            $this->redirect(route('admin.login'), navigate: false);

            return;
        }

        StaffSession::stamp((int) $saved->session_epoch);
        session()->forget(PasswordConfirmation::SESSION_KEY);
        session()->migrate(true);
        Auth::guard('web')->setUser($saved);
        session()->flash('toast', ['title' => __('staff.account.changed_title'), 'body' => __('staff.account.changed_body'), 'type' => 'success']);

        $this->redirect(route('admin.dashboard'), navigate: false);
    }

    public function render(): View
    {
        /** @var User|null $user */
        $user = auth()->user();

        return view('livewire.admin.account.password', [
            'user' => $user,
            'hasMcpAccess' => $user !== null && ($user->hasMcpAccess() || $user->mcpTokens()->whereNull('revoked_at')->exists()),
        ])->title(__('staff.account.title'));
    }
}
