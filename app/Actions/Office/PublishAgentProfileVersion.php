<?php

namespace App\Actions\Office;

use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Models\AgentProfile;
use App\Models\AgentProfileVersion;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Services\Ai\AnthropicChatModel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Publish a version of an agent (plan 41 §5, office.education): the version
 * must belong to the profile and name an allowed model (VO-10). Rollback =
 * publishing an older version. Audited with the old and new instructions
 * (SettingsAudit::recordText, area office, key office.education.{role},
 * Published). Analyses store the version ids they used.
 */
class PublishAgentProfileVersion
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, AgentProfile $profile, int $versionId, ?string $ip = null): AgentProfileVersion
    {
        if (! $actor->can('office.education')) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $profile, $versionId, $ip): AgentProfileVersion {
            $locked = AgentProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $version = AgentProfileVersion::query()->whereKey($versionId)->where('agent_profile_id', $locked->id)->first();

            if (! $version instanceof AgentProfileVersion || ! in_array($version->model, AnthropicChatModel::MODELS, true)) {
                throw ValidationException::withMessages(['version' => __('office.errors.version_invalid')]);
            }

            if ($locked->current_version_id === $version->id) {
                return $version;
            }

            $old = $locked->currentVersion;
            $locked->current_version_id = $version->id;
            $locked->save();

            SettingsAudit::recordText(SettingsArea::Office, 'office.education.'.$locked->role->value, AuditAction::Published, $actor, $ip, $old !== null ? 'v'.$old->version : '', 'v'.$version->version);

            return $version;
        });
    }
}
