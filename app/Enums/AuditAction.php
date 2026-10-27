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

    public function label(): string
    {
        return __("enums.audit_action.{$this->value}");
    }
}
