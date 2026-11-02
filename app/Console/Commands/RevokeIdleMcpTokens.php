<?php

namespace App\Console\Commands;

use App\Actions\Mcp\RevokeIdleMcpTokens as RevokeIdleMcpTokensAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Daily MCP token hygiene (plan 13 C12/C13, routes/console.php).
 */
#[Signature('mcp:revoke-idle')]
#[Description('Revoke idle or never-used MCP tokens and remind about tokens due for rotation')]
class RevokeIdleMcpTokens extends Command
{
    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Revokes MCP tokens that have been idle for too long or were never used, and sends reminders for tokens due for rotation.
Runs by itself every day; run by hand only after changing the idle-days setting.
Side effects: tokens stop working and reminders are sent. Scheduled daily.';

    /**
     * Execute the console command.
     */
    public function handle(RevokeIdleMcpTokensAction $revoke): int
    {
        $counts = $revoke->handle();

        $this->components->info("{$counts['idle']} idle and {$counts['never_used']} never-used MCP token(s) revoked, {$counts['reminded']} rotation reminder(s) sent.");

        return self::SUCCESS;
    }
}
