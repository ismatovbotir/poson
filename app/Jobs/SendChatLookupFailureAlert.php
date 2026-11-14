<?php

namespace App\Jobs;

use App\Support\Monitoring\MonitoringAlerts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Counts-only alert to the management chat: repeated failed order or repair
 * status lookups in the bot (plan 28 C3, CB-15). Queued, never sent from the
 * webhook request; carries no number or phone.
 */
class SendChatLookupFailureAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $failures)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        MonitoringAlerts::problem('chat_lookup_failures', ['failures' => $this->failures]);
    }
}
