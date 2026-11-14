<?php

namespace App\Enums;

/**
 * Where a job application stands (plan 31); any transition is allowed.
 */
enum JobApplicationStatus: string
{
    case New = 'new';
    case Reviewing = 'reviewing';
    case Interview = 'interview';
    case Rejected = 'rejected';
    case Hired = 'hired';

    public function label(): string
    {
        return __("enums.job_application_status.{$this->value}");
    }

    /**
     * Badge color (x-admin.badge).
     */
    public function color(): string
    {
        return match ($this) {
            self::New => 'sky',
            self::Reviewing => 'amber',
            self::Interview => 'violet',
            self::Rejected => 'muted',
            self::Hired => 'brand',
        };
    }
}
