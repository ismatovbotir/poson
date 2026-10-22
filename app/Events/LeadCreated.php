<?php

namespace App\Events;

use App\Models\Lead;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A lead was created (CreateLead, every channel). Dispatched only after the
 * transaction commits, so listeners never see a rolled-back lead. The
 * Telegram listener comes in plan 05c.
 */
class LeadCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Lead $lead) {}
}
