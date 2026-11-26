<?php

namespace App\Actions\Office;

use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Jobs\WipeBusinessDataChunk;
use App\Jobs\WipeGroupDataChunk;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\Admin\PasswordConfirmation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Delete all business data" (plan 41 §8.5, VO-12; office.settings = Admin,
 * recent password). Ingest is switched off FIRST (in the same transaction),
 * then a chunked queued job deletes every business chat (messages and
 * analyses cascade), connection and bot-user party mark, and nulls the
 * AiSummary activity meta on Telegram Business leads. Leads and the agent
 * teaching are kept. Plan 44 (OG-12): the groups switch goes off in the same
 * transaction and WipeGroupDataChunk empties every group (leads kept). Audited counts-free (key office.data, Deleted).
 */
class WipeBusinessData
{
    public const AUDIT_KEY = 'office.data';

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, ?string $ip = null): void
    {
        if (! $actor->can('office.settings')) {
            throw new AuthorizationException;
        }

        if (! PasswordConfirmation::isRecent()) {
            throw ValidationException::withMessages(['wipe' => __('office.errors.password_required')]);
        }

        DB::transaction(function () use ($actor, $ip): void {
            SaveOfficeSettings::disableIngest($actor, $ip);
            SaveOfficeSettings::disableGroups($actor, $ip);
            SettingsAudit::record(SettingsArea::Office, self::AUDIT_KEY, AuditAction::Deleted, $actor, $ip);
        });

        dispatch(WipeBusinessDataChunk::forNow())->afterCommit();
        dispatch(WipeGroupDataChunk::forNow())->afterCommit();
    }
}
