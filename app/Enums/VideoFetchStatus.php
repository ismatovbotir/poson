<?php

namespace App\Enums;

/**
 * State of the queued YouTube metadata fetch (plan 19): Pending until the job
 * runs, Ok after oEmbed answered, Unavailable when YouTube says the video is
 * private, removed or not embeddable (hidden on the site), Failed when the
 * retries ran out (still shown, with a placeholder or cached thumbnail).
 */
enum VideoFetchStatus: string
{
    case Pending = 'pending';
    case Ok = 'ok';
    case Unavailable = 'unavailable';
    case Failed = 'failed';

    public function label(): string
    {
        return __("enums.video_fetch_status.{$this->value}");
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Ok => 'brand',
            self::Unavailable => 'red',
            self::Failed => 'muted',
        };
    }
}
