<?php

namespace App\Enums;

/**
 * State of one Virtual office analysis run (plan 41 §6).
 */
enum AnalysisStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Capped = 'capped';

    public function label(): string
    {
        return __("enums.analysis_status.{$this->value}");
    }

    public function color(): string
    {
        return match ($this) {
            self::Queued, self::Running => 'sky',
            self::Done => 'brand',
            self::Failed => 'red',
            self::Skipped => 'muted',
            self::Capped => 'amber',
        };
    }

    public function isPending(): bool
    {
        return in_array($this, [self::Queued, self::Running], true);
    }

    /**
     * @return list<string>
     */
    public static function pendingValues(): array
    {
        return [self::Queued->value, self::Running->value];
    }
}
