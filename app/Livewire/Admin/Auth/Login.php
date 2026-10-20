<?php

namespace App\Livewire\Admin\Auth;

use App\Actions\Auth\AuthenticateStaff;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Staff sign-in (guest only). AuthenticateStaff applies the rate limit,
 * the active + staff-role check and the generic error message.
 */
#[Layout('layouts::admin-guest')]
class Login extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'email' => __('admin.auth.email'),
            'password' => __('admin.auth.password'),
        ];
    }

    public function login(AuthenticateStaff $authenticateStaff): void
    {
        $this->email = Str::lower(trim($this->email));

        try {
            $this->validate();

            $authenticateStaff->handle($this->email, $this->password, $this->remember, (string) request()->ip());
        } finally {
            $this->reset('password');
        }

        session()->regenerate();

        $this->redirectIntended(route('admin.dashboard', absolute: false), navigate: false);
    }

    public function render(): View
    {
        return view('livewire.admin.auth.login')
            ->title(__('admin.auth.page_title'));
    }
}
