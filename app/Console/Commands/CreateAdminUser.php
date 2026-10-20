<?php

namespace App\Console\Commands;

use App\Actions\Users\CreateStaffUser;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role as SpatieRole;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Create a staff account for the admin panel (there is no registration).
 * Interactive by default; every value can also be passed as an option:
 *
 *   php artisan admin:create-user --name="Aziz Karimov" --email=aziz@poson.uz \
 *       --role=admin --password=... --no-interaction
 *
 * Prefer the hidden password prompt: a --password value stays in the shell history.
 */
#[Signature('admin:create-user
    {--name= : Full name}
    {--email= : Login email}
    {--password= : Password; prefer the hidden prompt (omit this option), a value here stays in shell history}
    {--role= : admin, content_manager, sales_manager or service_engineer}
    {--locale= : Admin UI language: uz, ru or en (default uz)}')]
#[Description('Create an admin panel user with a staff role')]
class CreateAdminUser extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CreateStaffUser $createStaffUser): int
    {
        $interactive = $this->input->isInteractive();

        $data = [
            'name' => $this->option('name') ?? ($interactive ? text('Name', required: true) : null),
            'email' => $this->option('email') ?? ($interactive ? text('Email', required: true) : null),
            'role' => $this->option('role') ?? ($interactive ? select('Role', $this->roleOptions(), default: Role::ContentManager->value) : null),
            'locale' => $this->option('locale') ?? config('app.default_locale', 'uz'),
        ];

        if (is_string($data['email'])) {
            $data['email'] = Str::lower(trim($data['email']));
        }

        $password = $this->option('password');
        $passwordConfirmation = $password;

        if ($password === null && $interactive) {
            $password = password('Password', required: true, hint: 'At least 8 characters.');
            $passwordConfirmation = password('Confirm password', required: true);
        }

        $validator = Validator::make([...$data, 'password' => $password, 'password_confirmation' => $passwordConfirmation], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
            'role' => ['required', Rule::enum(Role::class)],
            'locale' => ['required', Rule::in(config('app.supported_locales'))],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $role = Role::from($data['role']);

        if (! SpatieRole::query()->where('name', $role->value)->where('guard_name', RolesAndPermissionsSeeder::GUARD)->exists()) {
            $this->components->info('Seeding roles and permissions first.');
            $this->callSilently('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
        }

        $user = $createStaffUser->handle($data['name'], $data['email'], $password, $role, $data['locale']);

        $this->components->info("User {$user->email} created with role {$role->value}.");

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    protected function roleOptions(): array
    {
        $options = [];

        foreach (Role::cases() as $role) {
            $options[$role->value] = $role->label();
        }

        return $options;
    }
}
