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
     * Execute the console command.
     */
    public function handle(RevokeIdleMcpTokensAction $revoke): int
    {
        $counts = $revoke->handle();

        $this->components->info("{$counts['idle']} idle and {$counts['never_used']} never-used MCP token(s) revoked, {$counts['reminded']} rotation reminder(s) sent.");

        return self::SUCCESS;
    }
}
