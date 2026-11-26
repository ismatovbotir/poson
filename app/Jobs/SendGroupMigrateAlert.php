<?php

namespace App\Jobs;

use App\Support\Monitoring\MonitoringAlerts;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Counts-only alert to the management chat (plan 44, OG-10): a tracked
 * group moved to a chat id that already is a tracked group, so nothing was
 * merged. Carries two message counts, never a title, id or name.
 */
class SendGroupMigrateAlert implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $sourceMessages, public int $targetMessages)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        MonitoringAlerts::problem('group_migrate_conflict', [
            'source_messages' => $this->sourceMessages,
            'target_messages' => $this->targetMessages,
        ]);
    }
}
