<?php

namespace App\Actions\Mcp;

use App\Enums\AuditAction;
use App\Enums\NewsStatus;
use App\Enums\SettingsArea;
use App\Models\McpCall;
use App\Models\NewsPost;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\Mcp\McpAlerts;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Take back a post Claude published (plan 13 M3-S9): "Unpublish" on a
 * create_post publish row of the MCP call log.
 *
 * Takes the call id, re-loads everything and re-checks inside one
 * transaction: the user may manage MCP, the call is a successful create_post
 * publish whose subject is a NewsPost, and under a row lock the post is still
 * Published (scheduled counts). Then status = Draft (published_at is kept),
 * an audit row is written and the alert is queued after commit. A missing,
 * foreign or already-draft target all give the same generic error.
 */
class UnpublishMcpPost
{
    public const TOOL = 'create_post';

    /**
     * Seconds after the call during which a change of the post still counts as the call's own (M3-L3).
     */
    public const EDIT_GRACE_SECONDS = 60;

    public function handle(int $callId, User $by, ?string $ip = null): NewsPost
    {
        $post = DB::transaction(function () use ($callId, $by, $ip): NewsPost {
            $call = McpCall::query()->find($callId);

            if (! $by->can('manage', McpCall::class)
                || ! $call instanceof McpCall
                || $call->tool !== self::TOOL
                || ! $call->is_publish
                || $call->subject_type !== (new NewsPost)->getMorphClass()
                || $call->subject_id === null) {
                throw self::unavailable();
            }

            $post = NewsPost::query()->lockForUpdate()->find($call->subject_id);

            if (! $post instanceof NewsPost || $post->status !== NewsStatus::Published) {
                throw self::unavailable();
            }

            // Edited by staff after the MCP call (re-published, curated): never taken down from the MCP log.
            if ($post->updated_at !== null && $post->updated_at->greaterThan($call->created_at->copy()->addSeconds(self::EDIT_GRACE_SECONDS))) {
                throw self::unavailable();
            }

            $post->update(['status' => NewsStatus::Draft]);

            SettingsAudit::record(SettingsArea::Mcp, 'mcp_posts.'.$post->id, AuditAction::Unpublished, $by, $ip);

            return $post;
        });

        McpAlerts::postUnpublished($post, $by);

        return $post;
    }

    private static function unavailable(): ValidationException
    {
        return ValidationException::withMessages(['call' => __('mcp.calls.unpublish_unavailable')]);
    }
}
