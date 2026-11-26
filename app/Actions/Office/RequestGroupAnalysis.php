<?php

namespace App\Actions\Office;

use App\Models\TelegramGroup;
use App\Models\TelegramGroupAnalysis;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * "Analyse now" on a tracked group (plan 44 §2, OG-4, OG-11): the analyse
 * policy (a group the user may see, office.analyse), the group gates
 * (QueueGroupAnalysis::isAnalysable), one request per group per
 * office.manual_cooldown_seconds, the shared office.manual_per_user_daily
 * budget of the user, never while an analysis of the group is pending, and
 * the per-chat daily cap. It only queues; nothing is sent to Telegram.
 */
class RequestGroupAnalysis
{
    public function __construct(protected QueueGroupAnalysis $queue) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, TelegramGroup $group): string
    {
        if (! Gate::forUser($actor)->allows('analyse', $group)) {
            throw new AuthorizationException;
        }

        if (! QueueGroupAnalysis::isAnalysable($group)) {
            throw ValidationException::withMessages(['analyse' => __('office.groups.errors.not_analysable')]);
        }

        if (QueueGroupAnalysis::hasPending($group)) {
            throw ValidationException::withMessages(['analyse' => __('office.errors.analysis_pending')]);
        }

        $userKey = 'office-analyse-user:'.$actor->id;

        if (RateLimiter::tooManyAttempts($userKey, (int) config('office.manual_per_user_daily', 50))) {
            throw ValidationException::withMessages(['analyse' => __('office.errors.analyse_limit')]);
        }

        if (! Cache::add('office:analyse-group-cooldown:'.$group->id, true, (int) config('office.manual_cooldown_seconds', 60))) {
            throw ValidationException::withMessages(['analyse' => __('office.errors.analyse_cooldown')]);
        }

        RateLimiter::hit($userKey, 86400);

        $outcome = DB::transaction(function () use ($actor, $group): string {
            $locked = TelegramGroup::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();

            if (QueueGroupAnalysis::hasPending($locked)) {
                return 'pending';
            }

            return $this->queue->handle($locked, TelegramGroupAnalysis::TRIGGER_MANUAL, $actor);
        });

        $message = match ($outcome) {
            QueueGroupAnalysis::CHAT_CAP => __('office.errors.chat_cap'),
            QueueGroupAnalysis::CAPPED => __('office.errors.capped'),
            QueueGroupAnalysis::GATED => __('office.groups.errors.not_analysable'),
            'pending' => __('office.errors.analysis_pending'),
            default => null,
        };

        if ($message !== null) {
            throw ValidationException::withMessages(['analyse' => $message]);
        }

        return $outcome;
    }
}
