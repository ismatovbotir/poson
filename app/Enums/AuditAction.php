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

    /**
     * Plan 13: MCP access granted to / transferred to a user.
     */
    case Granted = 'granted';
    case Transferred = 'transferred';

    /**
     * Plan 16: seeded catalog products published / rolled back to drafts in bulk.
     */
    case Published = 'published';
    case Unpublished = 'unpublished';

    /**
     * Plan 14 v2 B2: Telegram bot page (webhook set / deleted, bot enabled / disabled).
     */
    case WebhookSet = 'webhook_set';
    case WebhookDeleted = 'webhook_deleted';
    case Enabled = 'enabled';
    case Disabled = 'disabled';

    /**
     * Plan 21 stream A: staff inbox moderation and link checks (key
     * telegram.conversation.{id}, never a name or message text).
     */
    case Banned = 'banned';
    case Unbanned = 'unbanned';
    case Verified = 'verified';

    public function label(): string
    {
        return __("enums.audit_action.{$this->value}");
    }
}
