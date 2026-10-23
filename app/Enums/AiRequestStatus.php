<?php

namespace App\Enums;

/**
 * Lifecycle of an AI request (ai_requests.status): pending (queued) →
 * running → succeeded | failed. Rejected = refused before reaching the
 * provider (quota); rejected rows don't count towards the quota.
 */
enum AiRequestStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return __("enums.ai_request_status.{$this->value}");
    }

    /**
     * Badge color (x-admin.badge).
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'slate',
            self::Running => 'sky',
            self::Succeeded => 'brand',
            self::Failed => 'red',
            self::Rejected => 'amber',
        };
    }

    /**
     * Still waiting for the queue worker or the provider.
     */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Running;
    }

    /**
     * Statuses counted towards the daily quota.
     *
     * @return list<string>
     */
    public static function counted(): array
    {
        return [self::Pending->value, self::Running->value, self::Succeeded->value, self::Failed->value];
    }
}
