<?php

namespace App\Enums;

/**
 * Life of an applicant's CV (plan 31, CR-6): none = no file; pending = an
 * encrypted copy waits for the Telegram forward; sending = a worker claimed
 * it; forwarded = sent to the management chat (only Telegram's file_id is
 * kept); failed / expired = never delivered.
 */
enum JobApplicationCvStatus: string
{
    case None = 'none';
    case Pending = 'pending';
    case Sending = 'sending';
    case Forwarded = 'forwarded';
    case Failed = 'failed';
    case Expired = 'expired';

    public function label(): string
    {
        return __("enums.job_application_cv_status.{$this->value}");
    }

    /**
     * Badge color (x-admin.badge).
     */
    public function color(): string
    {
        return match ($this) {
            self::None => 'muted',
            self::Pending, self::Sending => 'amber',
            self::Forwarded => 'brand',
            self::Failed, self::Expired => 'red',
        };
    }
}
