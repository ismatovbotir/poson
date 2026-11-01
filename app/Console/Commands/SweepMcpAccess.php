<?php

namespace App\Console\Commands;

use App\Actions\Mcp\SweepMcpAccess as SweepMcpAccessAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Hourly MCP access check (plan 13 C8, routes/console.php).
 */
#[Signature('mcp:sweep')]
#[Description('Revoke MCP access of users who are no longer active Admins')]
class SweepMcpAccess extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SweepMcpAccessAction $sweep): int
    {
        $this->components->info($sweep->handle().' user(s) lost MCP access.');

        return self::SUCCESS;
    }
}
