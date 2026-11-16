<?php

namespace App\Actions\Catalog\Ai;

use App\Actions\Chat\RecordAiUsage;
use App\Enums\AiDraftStatus;
use App\Enums\AiUsageChannel;
use App\Jobs\RunAiDraftStage;
use App\Models\AiProductDraft;
use App\Models\User;
use App\Support\Chat\AiSwitch;
use App\Support\Media\ImageRejected;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Start stage A or B of a draft (plan 32 §7, PW-8, PW-9, PW-12, PW-29).
 * Called from the wizard's own methods (so the limiter is checked on every
 * /livewire/update call). Guards, cheap refusals first (CR#7):
 *  attempts limiter (hit on every call, 30 / 10 min / user, N2) → switch +
 *  key → stage per-user / global limit (read only) → (user lock →
 *  draft lock, fresh row) no other FRESH pending stage of this user (a
 *  stage older than AiDraftState::staleSeconds() is lost, M2) → no apply
 *  begun (N5) → state → read-only cap check → read-only global busy check
 *  (N2) → vision copies (stage A, at least one) → (global
 *  lock, L3) fresh pending globally ≤ pending_global → limiter hit → ONE
 *  conditional UPDATE to seeing / writing (0 rows = a double click: no
 *  dispatch) → dispatch RunAiDraftStage (id + stage) after commit on the
 *  ai-admin queue. The limiter is hit only for a stage that really starts.
 * Refusals by a limit or cap are counted with capped(). The caller has
 * authorized the user and loaded the draft by uuid + user.
 *
 * @return string "started" or a refusal key (admin.ai_wizard.refused.*)
 */
class StartAiDraftStage
{
    /**
     * Attempts (started or refused) per user per ATTEMPTS_DECAY seconds (N2).
     */
    public const ATTEMPTS = 30;

    public const ATTEMPTS_DECAY = 600;

    public function __construct(
        private readonly RecordAiUsage $usage,
        private readonly PrepareAiDraftImages $images,
    ) {}

    public function handle(User $user, AiProductDraft $draft, string $stage): string
    {
        if (! in_array($stage, RunAiDraftStage::STAGES, true)) {
            return 'error';
        }

        $channel = $stage === RunAiDraftStage::VISION ? AiUsageChannel::AdminVision : AiUsageChannel::AdminWrite;
        $limits = (array) config('chatbot.ai.product.limits');

        // Every call counts (N2): refusals (busy, pending, state, …) are bounded too.
        if (RateLimiter::tooManyAttempts($this->attemptsKey($user), self::ATTEMPTS)) {
            $this->usage->capped($channel);

            return 'limited';
        }

        RateLimiter::hit($this->attemptsKey($user), self::ATTEMPTS_DECAY);

        if (! AiSwitch::adminEnabled()) {
            return 'disabled';
        }

        if ($this->limited($user, $stage, $limits)) {
            $this->usage->capped($channel);

            return 'limited';
        }

        try {
            return Cache::lock(self::userLockKey($user), 60)->block(5, fn (): string => AiDraftLock::run(
                $draft->id,
                fn (): string => $this->start($user, $draft, $stage, $channel, $limits),
                5,
            ));
        } catch (LockTimeoutException) {
            return 'pending';
        }
    }

    /**
     * @param  array<string, mixed>  $limits
     */
    private function start(User $user, AiProductDraft $draft, string $stage, AiUsageChannel $channel, array $limits): string
    {
        $pending = AiDraftStatus::values(AiDraftStatus::pending());

        if (AiProductDraft::query()->where('user_id', $user->id)->whereIn('status', $pending)->where('updated_at', '>=', AiDraftState::staleBefore())->count() >= max(1, (int) ($limits['pending_user'] ?? 1))) {
            return 'pending';
        }

        $draft->refresh();
        $values = ['error' => null];

        // An apply has begun (N5): only "Open product" / "Discard" from here.
        if (AiDraftLock::applyStarted($draft)) {
            return 'state';
        }

        if ($stage === RunAiDraftStage::VISION) {
            if (! in_array($draft->status, AiDraftStatus::visionFrom(), true)) {
                return 'state';
            }
        } elseif (! in_array($draft->status, AiDraftStatus::writeFrom(), true) || $draft->facts === null || ! ($draft->result['facts_confirmed'] ?? false)) {
            return 'state';
        }

        if (! $this->usage->withinCaps($channel)) {
            $this->usage->capped($channel);

            return 'capped';
        }

        // Cheap read-only pre-check before any image work (N2); re-checked under the global lock.
        if ($this->globalBusy($limits)) {
            return 'busy';
        }

        if ($stage === RunAiDraftStage::VISION) {
            try {
                $copies = $this->images->visionCopies($draft);
            } catch (ImageRejected) {
                $this->images->deleteVision($draft->uuid);

                return 'image';
            }

            if ($copies['paths'] === []) {
                $this->images->deleteVision($draft->uuid);

                return 'no_images';
            }

            $values['input'] = [...$draft->input, 'vision_map' => $copies['map']];
            $from = AiDraftStatus::visionFrom();
            $to = AiDraftStatus::Seeing;
        } else {
            $from = AiDraftStatus::writeFrom();
            $to = AiDraftStatus::Writing;
        }

        try {
            $outcome = Cache::lock('ai-wizard:start:global', 10)->block(5, fn (): string => $this->move($user, $draft, $stage, $channel, $limits, $from, $to, $values));
        } catch (LockTimeoutException) {
            $outcome = 'busy';
        }

        if ($outcome !== 'started') {
            if ($stage === RunAiDraftStage::VISION) {
                $this->images->deleteVision($draft->uuid);
            }

            return $outcome;
        }

        dispatch(new RunAiDraftStage($draft->id, $stage))->afterCommit();

        return 'started';
    }

    /**
     * Under the global lock: the global pending count, the limiter hit and the state UPDATE as one step.
     *
     * @param  array<string, mixed>  $limits
     * @param  list<AiDraftStatus>  $from
     * @param  array<string, mixed>  $values
     */
    private function move(User $user, AiProductDraft $draft, string $stage, AiUsageChannel $channel, array $limits, array $from, AiDraftStatus $to, array $values): string
    {
        if ($this->globalBusy($limits)) {
            return 'busy';
        }

        if ($this->limited($user, $stage, $limits)) {
            $this->usage->capped($channel);

            return 'limited';
        }

        if (! AiDraftState::move($draft->id, $from, $to, $values, $user->id)) {
            return 'state';
        }

        RateLimiter::hit($this->userKey($user, $stage), 3600);
        RateLimiter::hit($this->globalKey($stage), 3600);

        return 'started';
    }

    /**
     * Fresh pending stages of all users reach pending_global.
     *
     * @param  array<string, mixed>  $limits
     */
    private function globalBusy(array $limits): bool
    {
        return AiProductDraft::query()
            ->whereIn('status', AiDraftStatus::values(AiDraftStatus::pending()))
            ->where('updated_at', '>=', AiDraftState::staleBefore())
            ->count() >= max(1, (int) ($limits['pending_global'] ?? 2));
    }

    /**
     * The user's start lock, also held while a new draft is opened (SaveAiDraftInfo).
     */
    public static function userLockKey(User $user): string
    {
        return "ai-wizard:start:user:{$user->id}";
    }

    private function attemptsKey(User $user): string
    {
        return "ai-wizard:attempts:{$user->id}";
    }

    /**
     * @param  array<string, mixed>  $limits
     */
    private function limited(User $user, string $stage, array $limits): bool
    {
        return RateLimiter::tooManyAttempts($this->userKey($user, $stage), (int) ($limits["{$stage}_per_hour_user"] ?? 10))
            || RateLimiter::tooManyAttempts($this->globalKey($stage), (int) ($limits["{$stage}_per_hour_global"] ?? 60));
    }

    private function userKey(User $user, string $stage): string
    {
        return "ai-wizard:{$stage}:user:{$user->id}";
    }

    private function globalKey(string $stage): string
    {
        return "ai-wizard:{$stage}:global";
    }
}
