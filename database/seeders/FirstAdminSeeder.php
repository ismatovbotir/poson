<?php

namespace Database\Seeders;

use App\Actions\Users\CreateStaffUser;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * The first Admin account (plan 15 B) from config admin.seed_login /
 * admin.seed_password (ADMIN_SEED_LOGIN / ADMIN_SEED_PASSWORD; never env()
 * here). Run by LaunchSeeder after the roles exist.
 *
 * - The email is trimmed and lower-cased and must be
 *   valid; the password needs at least 12 characters. Otherwise a
 *   RuntimeException names the setting, never the value, and no user is created.
 * - An existing email changes nothing (no password, role or name change):
 *   a notice is printed and seeding continues.
 * - With both settings removed (after the first seed) a re-run skips this
 *   step when an active Admin already exists.
 * - The user is created through CreateStaffUser with the Admin role.
 * - The password is never printed, logged or put into an exception.
 */
class FirstAdminSeeder extends Seeder
{
    public const MIN_PASSWORD = 12;

    public function run(CreateStaffUser $createStaffUser): void
    {
        $login = config('admin.seed_login');
        $email = is_string($login) ? Str::lower(trim($login)) : '';

        if ($this->adminExists()) {
            $this->command?->warn('An active Admin already exists: first admin step skipped. Remove ADMIN_SEED_LOGIN and ADMIN_SEED_PASSWORD from .env.');

            return;
        }

        $this->validate($email);

        if (User::query()->where('email', $email)->exists()) {
            $this->command?->warn("First admin {$email} already exists: nothing changed.");

            return;
        }

        if (! SpatieRole::query()->where('name', Role::Admin->value)->where('guard_name', RolesAndPermissionsSeeder::GUARD)->exists()) {
            $this->call(RolesAndPermissionsSeeder::class);
        }

        $createStaffUser->handle('Admin', $email, (string) config('admin.seed_password'), Role::Admin, config('app.default_locale', 'uz'));

        $this->command?->info("First admin {$email} created with role admin. Remove ADMIN_SEED_LOGIN and ADMIN_SEED_PASSWORD from .env.");
    }

    protected function adminExists(): bool
    {
        return User::query()->where('is_active', true)->role(Role::Admin->value, RolesAndPermissionsSeeder::GUARD)->exists();
    }

    /**
     * @throws RuntimeException naming the invalid settings (never their values)
     */
    protected function validate(string $email): void
    {
        $validator = Validator::make(
            ['email' => $email, 'password' => config('admin.seed_password')],
            [
                'email' => ['required', 'string', 'email', 'max:255'],
                'password' => ['required', 'string', Password::min(self::MIN_PASSWORD)],
            ],
        );

        if (! $validator->fails()) {
            return;
        }

        $problems = [];

        if ($validator->errors()->has('email')) {
            $problems[] = 'ADMIN_SEED_LOGIN must be a valid email address';
        }

        if ($validator->errors()->has('password')) {
            $problems[] = 'ADMIN_SEED_PASSWORD must be at least '.self::MIN_PASSWORD.' characters';
        }

        throw new RuntimeException('First admin not created: '.implode('; ', $problems).'. Set them in .env, then run `php artisan config:clear` and the seeder again.');
    }
}
