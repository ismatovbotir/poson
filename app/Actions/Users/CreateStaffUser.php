<?php

namespace App\Actions\Users;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create an active staff account with one role (used by FirstAdminSeeder;
 * input is validated by the caller).
 */
class CreateStaffUser
{
    public function handle(string $name, string $email, #[\SensitiveParameter] string $password, Role $role, ?string $locale = null): User
    {
        return DB::transaction(function () use ($name, $email, $password, $role, $locale): User {
            $user = User::create([
                'name' => trim($name),
                'email' => Str::lower(trim($email)),
                'password' => $password,
                'locale' => $locale ?? config('app.default_locale', 'uz'),
            ]);

            $user->forceFill(['is_active' => true, 'email_verified_at' => now()])->save();
            $user->assignRole($role);

            return $user;
        });
    }
}
