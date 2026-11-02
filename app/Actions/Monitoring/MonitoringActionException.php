<?php

namespace App\Actions\Monitoring;

use RuntimeException;

/**
 * A monitoring action was refused. reason is a key of monitoring.errors.*:
 * password (needs re-confirmation), invalid, running, cooldown, rate,
 * too_many, class.
 */
final class MonitoringActionException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Monitoring action refused: {$reason}");
    }
}
