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
    case RetryForward = 'retry_forward';
    case Linked = 'linked';

    /**
     * Plan 21 A7: an Admin deleted a conversation (T21-18).
     */
    case Deleted = 'deleted';

    /**
     * Plan 24: a staff password was changed by its owner or reset (key
     * user.{id}.password[.cli], never a name or the password).
     */
    case PasswordChanged = 'password_changed';
    case PasswordReset = 'password_reset';

    /**
     * Plan 40 (YD-12): an offline-conversion CSV was downloaded (key holds the
     * identifier type, date range and row count; never an id or amount).
     */
    case Exported = 'exported';

    /**
     * Plan 41: a Telegram Business chat marked as client / employee / ignored (never a name).
     */
    case Marked = 'marked';

    /**
     * Plan 20 M6: "Sync menu buttons" on the Mini App page (actor and IP only).
     */
    case SyncRequested = 'sync_requested';

    /**
     * Plan 44 T5: Telegram groups (key office.group.{id}.*, never a title or a name): tracked (with the
     * privacy-note tick), ignored, a member's role set, a member forgotten.
     */
    case GroupTracked = 'group_tracked';
    case GroupIgnored = 'group_ignored';
    case RolesSet = 'roles_set';
    case Forgotten = 'forgotten';
    case GroupUntracked = 'group_untracked';

    /**
     * Plan 46 T6: a draft feedback row promoted to an example in a new unpublished agent version (ids only).
     */
    case Promoted = 'promoted';

    public function label(): string
    {
        return __("enums.audit_action.{$this->value}");
    }
}
