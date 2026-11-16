<?php

namespace App\Jobs;

use App\Actions\Catalog\Ai\AiDraftState;
use App\Actions\Catalog\Ai\AiStageSkipped;
use App\Actions\Catalog\Ai\AiWizardGate;
use App\Actions\Catalog\Ai\PrepareAiDraftImages;
use App\Actions\Catalog\Ai\SeeProductPhotos;
use App\Actions\Catalog\Ai\WriteProductTexts;
use App\Actions\Chat\RecordAiUsage;
use App\Enums\AiDraftStatus;
use App\Enums\ChatAiStatus;
use App\Models\AiProductDraft;
use App\Services\Ai\AiFailed;
use App\Services\Ai\AiResult;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\ChatModel;
use App\Support\Chat\AiSwitch;
use App\Support\Chat\ChatSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One stage of an AI product wizard draft (plan 32 §6, PW-7 - PW-12).
 * Payload: the draft id and the stage name only (PW-9; dispatched
 * ->afterCommit() on the "ai-admin" queue). tries 1; timeout 60 (vision) /
 * 150 (write), below the queue's retry_after and above the SDK timeout +
 * connect (PW-10, test). The model is resolved in handle(), never here.
 *
 *  0. A non-blocking claim lock ai-draft:run:{id} (timeout + 30 s): a second
 *     delivery while one runs returns at once (N4); updated_at is touched so
 *     the stale clock (AiDraftState) starts when the job really starts.
 *  1. The draft must be in this stage's pending status (seeing / writing).
 *  2. Gate::forUser(draft user): active, ai.generate, switch on, create /
 *     update + useType (PW-7) — else failed "not_allowed", no call.
 *  3. The request is built (stage A: every vision copy must still exist,
 *     else failed "no_images", M4) — before any reservation.
 *  4. Reserve the stage channel's daily cap (AI-18, PW-12) — else "capped".
 *  5. The call, outside any transaction; the reservation is settled in finally.
 *  6. Fresh switch memo, the gate again; store the validated result only if
 *     the status is still the pending one (a cancel wins, PW-8).
 * Every Throwable ends in a fixed error key (PW-11); logs carry ids, stage,
 * model, status, tokens and ms only. The vision copies are deleted in
 * finally and in failed(); cleaned images never are.
 */
class RunAiDraftStage implements ShouldQueue
{
    use Queueable;

    public const VISION = 'vision';

    public const WRITE = 'write';

    public const STAGES = [self::VISION, self::WRITE];

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public int $draftId, public string $stage)
    {
        $this->timeout = (int) config("chatbot.ai.product.{$stage}.job_timeout", $stage === self::VISION ? 60 : 150);
        $this->onQueue((string) config('chatbot.ai.product.queue', 'ai-admin'));
    }

    public function pendingStatus(): AiDraftStatus
    {
        return $this->stage === self::VISION ? AiDraftStatus::Seeing : AiDraftStatus::Writing;
    }

    public function handle(ChatModel $model, RecordAiUsage $usage, SeeProductPhotos $vision, WriteProductTexts $write, PrepareAiDraftImages $images): void
    {
        if (! in_array($this->stage, self::STAGES, true)) {
            return;
        }

        // One running attempt per draft (N4): a second delivery of the same job finds the claim held.
        $claim = Cache::lock("ai-draft:run:{$this->draftId}", $this->timeout + 30);

        if (! $claim->get()) {
            return;
        }

        try {
            $this->run($model, $usage, $vision, $write, $images);
        } finally {
            $claim->release();
        }
    }

    private function run(ChatModel $model, RecordAiUsage $usage, SeeProductPhotos $vision, WriteProductTexts $write, PrepareAiDraftImages $images): void
    {
        // The stale clock starts when the job really starts (N4). MySQL counts changed rows: a touch in
        // the same second as the start changes nothing, so the fresh row decides, not the affected count.
        // A stage already stale (L2) is never revived by the touch: it is failed and no model call is made.
        AiProductDraft::query()->whereKey($this->draftId)->where('status', $this->pendingStatus()->value)
            ->where('updated_at', '>=', AiDraftState::staleBefore())
            ->update(['updated_at' => now()]);

        $draft = AiProductDraft::query()->find($this->draftId);

        if ($draft === null || $draft->status !== $this->pendingStatus()) {
            return;
        }

        if (AiDraftState::isStale($draft)) {
            AiDraftState::failStale($this->draftId);

            return;
        }

        $runner = $this->stage === self::VISION ? $vision : $write;
        $channel = $runner->channel();
        $reservation = null;
        $result = null;
        $status = ChatAiStatus::Failed;
        $outcome = 'error';

        try {
            if (! AiWizardGate::allowsDraft($draft)) {
                $this->finish($draft, AiDraftStatus::Failed, ['error' => 'not_allowed']);
                $outcome = 'not_allowed';

                return;
            }

            try {
                $request = $runner->request($draft);
            } catch (AiStageSkipped $skipped) {
                $this->finish($draft, AiDraftStatus::Failed, ['error' => $skipped->errorKey()]);
                $outcome = $skipped->errorKey();

                return;
            }

            $tokens = $request->estimatedBilledTokens();
            $day = $usage->reserve($channel, $tokens);

            if ($day === null) {
                $usage->capped($channel);
                $this->finish($draft, AiDraftStatus::Capped, ['error' => 'capped']);
                $outcome = 'capped';

                return;
            }

            $reservation = ['day' => $day, 'tokens' => $tokens];

            try {
                $result = $model->complete($request);
            } catch (AiUnavailable) {
                $this->finish($draft, AiDraftStatus::Failed, ['error' => 'unavailable']);
                $outcome = 'unavailable';

                return;
            } catch (AiFailed $failure) {
                if (in_array($failure->status, [401, 403], true)) {
                    AiSwitch::markAuthFailed();
                }

                $this->finish($draft, AiDraftStatus::Failed, ['error' => 'api_error']);
                $outcome = 'api_error';

                return;
            }

            ChatSettings::forgetMemo();

            if (! AiWizardGate::allowsDraft($draft->refresh())) {
                $status = ChatAiStatus::Blocked;
                $this->finish($draft, AiDraftStatus::Failed, ['error' => 'not_allowed'] + $this->usageColumns($result));
                $outcome = 'not_allowed';

                return;
            }

            if ($result->isRefusal()) {
                $status = ChatAiStatus::Refused;
                $this->finish($draft, AiDraftStatus::Refused, ['error' => 'refused'] + $this->usageColumns($result));
                $outcome = 'refused';

                return;
            }

            $stored = $runner->store($draft, $result);
            $status = match ($stored['status']) {
                AiDraftStatus::Truncated => ChatAiStatus::Truncated,
                AiDraftStatus::Failed => ChatAiStatus::Blocked,
                default => ChatAiStatus::Answered,
            };
            $this->finish($draft, $stored['status'], $stored['values'] + $this->usageColumns($result));
            $outcome = $stored['status']->value;
        } catch (Throwable $error) {
            $this->finish($draft, AiDraftStatus::Failed, ['error' => 'error'] + ($result instanceof AiResult ? $this->usageColumns($result) : []));
            $outcome = 'error:'.class_basename($error);
        } finally {
            if ($reservation !== null) {
                try {
                    $usage->settle($channel, $reservation['day'], $reservation['tokens'], $status, $result);
                } catch (Throwable) {
                    // Counters only: a failed settle must not hide the outcome.
                }
            }

            if ($this->stage === self::VISION) {
                $images->deleteVision($draft->uuid);
            }

            Log::info('ai.draft.stage', [
                'draft_id' => $draft->id,
                'user_id' => $draft->user_id,
                'stage' => $this->stage,
                'model' => $result?->model,
                'outcome' => $outcome,
                'input_tokens' => $result?->inputTokens,
                'output_tokens' => $result?->outputTokens,
                'ms' => $result?->latencyMs,
            ]);
        }
    }

    public function failed(?Throwable $error): void
    {
        $draft = AiProductDraft::query()->find($this->draftId);

        if ($draft === null) {
            return;
        }

        AiDraftState::move($draft->id, [$this->pendingStatus()], AiDraftStatus::Failed, ['error' => 'error']);

        if ($this->stage === self::VISION) {
            app(PrepareAiDraftImages::class)->deleteVision($draft->uuid);
        }
    }

    /**
     * Store only while the draft is still in this stage (a cancel or expiry wins).
     *
     * @param  array<string, mixed>  $values
     */
    private function finish(AiProductDraft $draft, AiDraftStatus $to, array $values): bool
    {
        return AiDraftState::move($draft->id, [$this->pendingStatus()], $to, $values);
    }

    /**
     * @return array<string, int|string>
     */
    private function usageColumns(AiResult $result): array
    {
        $prefix = $this->stage === self::VISION ? 'vision' : 'write';

        return [
            "{$prefix}_model" => mb_substr($result->model !== '' ? $result->model : (string) config("chatbot.ai.product.{$this->stage}.model"), 0, 40),
            "{$prefix}_input_tokens" => max(0, $result->inputTokens + $result->cacheReadTokens + $result->cacheWriteTokens),
            "{$prefix}_output_tokens" => max(0, $result->outputTokens),
            "{$prefix}_ms" => max(0, $result->latencyMs),
        ];
    }
}
