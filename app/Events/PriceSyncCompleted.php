<?php

namespace App\Events;

use App\Models\PriceSyncLog;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A price API batch was logged (applied, partial or rejected; plan 09).
 * Dispatched only after the batch's transaction commits.
 */
class PriceSyncCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public PriceSyncLog $log) {}
}
