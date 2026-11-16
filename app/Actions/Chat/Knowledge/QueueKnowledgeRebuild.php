<?php

namespace App\Actions\Chat\Knowledge;

use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Jobs\RebuildAiKnowledgeIndex;
use App\Models\SettingsAudit;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

/**
 * Chat bot → Settings → Website knowledge → "Rebuild now" (plan 33 K-11).
 * Needs chatbot.settings (checked here too, not only in the component). One
 * global lock for five minutes: a second click inside it dispatches nothing.
 * The queued job is unique. The audit row names the setting key only.
 */
class QueueKnowledgeRebuild
{
    public const LOCK_KEY = 'chatbot:knowledge:rebuild';

    public const LOCK_SECONDS = 300;

    /**
     * @return bool false when a rebuild was already requested in the last five minutes
     */
    public function handle(User $actor, ?string $ip): bool
    {
        Gate::forUser($actor)->authorize('chatbot.settings');

        if (! Cache::add(self::LOCK_KEY, 1, self::LOCK_SECONDS)) {
            return false;
        }

        Cache::forget(RebuildAiKnowledgeIndex::CURSOR_KEY);
        SettingsAudit::record(SettingsArea::Chatbot, 'ai_knowledge_rebuild', AuditAction::Generated, $actor, $ip);
        RebuildAiKnowledgeIndex::dispatch();

        return true;
    }
}
