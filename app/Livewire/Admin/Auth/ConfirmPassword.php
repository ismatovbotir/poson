<?php

namespace App\Livewire\Admin\Auth;

use App\Livewire\Admin\Concerns\ChecksStaffPassword;
use App\Models\User;
use App\Support\Admin\PasswordConfirmation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Re-enter the password before sensitive changes (/admin/confirm-password,
 * plan 04 S7). On success the confirmation is valid for 15 minutes, the
 * session ID is rotated and the user goes back to the page that sent them
 * here (url.intended, always an admin URL), else to the dashboard.
 *
 * Wrong passwords: see ChecksStaffPassword (shared with the change-password
 * screen).
 *
 * Dev login: only where AuthenticateStaff::devLoginEnabled() (APP_ENV=local
 * + ADMIN_DEV_LOGIN + APP_DEBUG + private IP) any password is accepted,
 * like the login itself. In every other environment the real password is
 * checked, also for accounts that signed in through the dev login.
 */
#[Layout('layouts::admin-guest')]
class ConfirmPassword extends Component
{
    use ChecksStaffPassword;

    public string $password = '';

    public function confirm(): void
    {
        try {
            $this->validate(['password' => ['required', 'string', 'max:255']], [], ['password' => __('admin.auth.password')]);

            /** @var User $user */
            $user = auth()->user();

            if (! $this->checkStaffPassword($user, $this->password, 'password', allowDevLogin: true)) {
                return;
            }
        } finally {
            $this->reset('password');
        }

        session()->migrate(true);
        PasswordConfirmation::confirm();

        $intended = (string) session()->pull('url.intended', '');
        $target = $this->isAdminUrl($intended) ? $intended : route('admin.dashboard');

        $this->redirect($target, navigate: false);
    }

    /**
     * Only same-host /admin URLs are followed (no open redirect).
     */
    protected function isAdminUrl(string $url): bool
    {
        $admin = route('admin.dashboard');

        return $url !== '' && ($url === $admin || Str::startsWith($url, rtrim($admin, '/').'/'));
    }

    public function render(): View
    {
        return view('livewire.admin.auth.confirm-password')
            ->title(__('admin.confirm_password.title'));
    }
}
