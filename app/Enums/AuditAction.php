<?php

namespace App\Enums;

/**
 * Kind of settings change recorded in settings_audits (never the value).
 */
enum AuditAction: string
{
    case Updated = 'updated';
    case Cleared = 'cleared';
    case Uploaded = 'uploaded';
    case Removed = 'removed';
    case Generated = 'generated';
    case Created = 'created';
    case Revoked = 'revoked';
    case Unlinked = 'unlinked';

    /**
     * Plan 15 C: the search indexing switch was opened / closed (the audit
     * row's action carries the new state; the old one is its opposite).
     */
    case Opened = 'opened';
    case Closed = 'closed';

    public function label(): string
    {
        return __("enums.audit_action.{$this->value}");
    }
}
