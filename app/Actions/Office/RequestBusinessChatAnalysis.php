<?php

namespace App\Actions\Office;

use App\Models\BusinessChat;
use App\Models\BusinessChatAnalysis;
use App\Models\User;
use App\Support\Office\OfficeSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * "Analyse now" on a chat (plan 41 §6.1, VO-9, VO-11): the analyse policy
 * (a chat the user may see), the office AI on, one request per chat per
 * office.manual_cooldown_seconds, at most office.manual_per_user_daily a
 * day per user, never while an analysis of the chat is queued or running,
 * and the per-chat daily cap (QueueBusinessChatAnalysis).
 */
class RequestBusinessChatAnalysis
{
    public function __construct(protected QueueBusinessChatAnalysis $queue) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, BusinessChat $chat): string
    {
        if (! Gate::forUser($actor)->allows('analyse', $chat)) {
            throw new AuthorizationException;
        }

        if (! OfficeSettings::aiEnabled()) {
            throw ValidationException::withMessages(['analyse' => __('office.errors.ai_off')]);
        }

        if ($chat->hasPendingAnalysis()) {
            throw ValidationException::withMessages(['analyse' => __('office.errors.analysis_pending')]);
        }

        $userKey = 'office-analyse-user:'.$actor->id;

        if (RateLimiter::tooManyAttempts($userKey, (int) config('office.manual_per_user_daily', 50))) {
            throw ValidationException::withMessages(['analyse' => __('office.errors.analyse_limit')]);
        }

        if (! Cache::add('office:analyse-cooldown:'.$chat->id, true, (int) config('office.manual_cooldown_seconds', 60))) {
            throw ValidationException::withMessages(['analyse' => __('office.errors.analyse_cooldown')]);
        }

        RateLimiter::hit($userKey, 86400);

        $outcome = DB::transaction(function () use ($actor, $chat): string {
            $locked = BusinessChat::query()->whereKey($chat->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isClient() || $locked->hasPendingAnalysis()) {
                return 'pending';
            }

            return $this->queue->handle($locked, BusinessChatAnalysis::TRIGGER_MANUAL, $actor);
        });

        if ($outcome === QueueBusinessChatAnalysis::CHAT_CAP) {
            throw ValidationException::withMessages(['analyse' => __('office.errors.chat_cap')]);
        }

        if ($outcome === QueueBusinessChatAnalysis::CAPPED) {
            throw ValidationException::withMessages(['analyse' => __('office.errors.capped')]);
        }

        if ($outcome === 'pending') {
            throw ValidationException::withMessages(['analyse' => __('office.errors.analysis_pending')]);
        }

        return $outcome;
    }
}
