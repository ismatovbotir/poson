<?php

namespace App\Console\Commands;

use App\Actions\Users\ResetStaffPasswordFromConsole;
use App\Support\Admin\TempPassword;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Recovery when no Admin can sign in (plan 24 A1, P24-9). Never scheduled and
 * never in monitoring.manual_tasks: it prints a password.
 */
#[Signature('staff:reset-password {email : Email of the active staff member}')]
#[Description('Give an active staff member a temporary password from the server console')]
class StaffResetPassword extends Command
{
    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Recovery for when no Admin can sign in. Asks for confirmation, then prints a temporary password once.
The temporary password works for 24 hours and must be changed at first use. Pass it in person or by phone, not in a Telegram group.
Side effects: ends all sessions and remember cookies of the account, revokes its MCP tokens and MCP access (access must be granted again, then a new token generated), writes an audit row and alerts the management chat. It never reactivates an inactive account.
Run it by hand on the server only. Not scheduled.';

    /**
     * Execute the console command.
     */
    public function handle(ResetStaffPasswordFromConsole $reset): int
    {
        if (! $this->confirm(__('staff.reset_command.confirm'))) {
            $this->components->warn(__('staff.reset_command.cancelled'));

            return self::FAILURE;
        }

        $result = $reset->handle((string) $this->argument('email'));

        if ($result === null) {
            $this->components->error(__('staff.reset_command.refused'));

            return self::FAILURE;
        }

        $this->components->info(__('staff.reset_command.done', ['hours' => TempPassword::VALID_HOURS]));
        $this->line($result['password']);

        if (! $result['alerted']) {
            $this->components->warn(__('staff.reset_command.alert_undeliverable'));
        }

        return self::SUCCESS;
    }
}
