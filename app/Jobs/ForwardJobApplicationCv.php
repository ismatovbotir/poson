<?php

namespace App\Jobs;

use App\Enums\ActivityType;
use App\Enums\JobApplicationCvStatus;
use App\Models\Activity;
use App\Models\JobApplication;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Careers\CareersAlerts;
use App\Support\Careers\CareersCv;
use App\Support\Careers\CareersSwitch;
use App\Support\Careers\JobApplicationMessage;
use App\Support\Careers\PendingCv;
use App\Support\Telegram\PrivateGroupTarget;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Forward a candidate's CV to the management chat (plan 31 §5, CR-5 / CR-6 /
 * CR-9 / CR-10). Payload: the application id only.
 *
 *  1. Atomic claim: cv_status pending → sending (a second worker, or a
 *     deleted row, does nothing).
 *  2. Decrypt the pending copy; failure (APP_KEY rotated, file gone) →
 *     failed + text fallback.
 *  3. Target check (PrivateGroupTarget, CR-9): refused → back to pending
 *     (expires after 24 h) + refused_target alert.
 *  4. sendDocument "CV-{id}.pdf" with the JobApplicationMessage caption;
 *     success → file_id, file_unique_id, message_id, forwarded_at stored and
 *     the pending file deleted at once (a failed delete → counts-only alert).
 *
 * 429 → back to pending and released for retry_after; 400 / 403 → failed +
 * text fallback + alert; 401 / 404 / 5xx / timeout → back to pending and
 * retried with backoff until created_at + pending_cv_hours. $timeout (45 s)
 * is above the sendDocument timeout (30 s) and below the queue retry_after
 * (90 s); a run killed by it (failOnTimeout is off: the claim and the
 * 15-minute recovery below are the design) leaves "sending", which careers:expire-pending-cvs
 * puts back after 15 minutes. Logs: id and code only.
 */
class ForwardJobApplicationCv implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $timeout = 45;

    public bool $failOnTimeout = false;

    public int $maxExceptions = 10;

    public function __construct(public int $jobApplicationId) {}

    public function retryUntil(): DateTimeInterface
    {
        $created = JobApplication::query()->whereKey($this->jobApplicationId)->value('created_at');

        return ($created !== null ? now()->parse($created) : now())->addHours((int) config('careers.pending_cv_hours', 24));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800, 3600];
    }

    public function handle(TelegramClient $client): void
    {
        $claimed = JobApplication::query()
            ->whereKey($this->jobApplicationId)
            ->where('cv_status', JobApplicationCvStatus::Pending->value)
            ->update(['cv_status' => JobApplicationCvStatus::Sending->value, 'updated_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $application = JobApplication::query()->with('vacancy:id,title')->find($this->jobApplicationId);

        if ($application === null) {
            return;
        }

        try {
            $this->forward($client, $application);
        } catch (TelegramException $exception) {
            $this->handleTelegramFailure($exception, $application);
        } catch (Throwable $exception) {
            $this->backToPending($application);

            throw $exception;
        }
    }

    /**
     * Retries used up (or the window passed): failed + text fallback, once.
     */
    public function failed(?Throwable $exception): void
    {
        $application = JobApplication::query()->find($this->jobApplicationId);

        if ($application !== null && in_array($application->cv_status, [JobApplicationCvStatus::Pending, JobApplicationCvStatus::Sending], true)) {
            self::markUndelivered($application, JobApplicationCvStatus::Failed);
        }
    }

    /**
     * Delete the pending copy, set the final state and send the text-only message.
     */
    public static function markUndelivered(JobApplication $application, JobApplicationCvStatus $status): void
    {
        if (! PendingCv::delete($application->cv_pending_path)) {
            CareersAlerts::once('delete_failed');
        }

        $updated = JobApplication::query()->whereKey($application->id)
            ->whereIn('cv_status', [JobApplicationCvStatus::Pending->value, JobApplicationCvStatus::Sending->value])
            ->update(['cv_status' => $status->value, 'cv_pending_path' => null, 'updated_at' => now()]);

        if ($updated > 0) {
            NotifyJobApplication::dispatch($application->id, true);
        }
    }

    /**
     * @throws TelegramException
     */
    private function forward(TelegramClient $client, JobApplication $application): void
    {
        if (! CareersSwitch::cvEnabled()) {
            self::markUndelivered($application, JobApplicationCvStatus::Failed);

            return;
        }

        $chatId = CareersCv::chatId();

        if (! $client->isConfigured() || $chatId === null) {
            if ($client->isConfigured() && CareersCv::chatIsSet()) {
                CareersAlerts::once('refused_target');
            }

            $this->backToPending($application);
            $this->release(600);

            return;
        }

        $bytes = PendingCv::read($application->cv_pending_path);

        if ($bytes === null) {
            Log::warning('Careers CV forward failed', ['job_application' => $application->id, 'code' => 'decrypt']);
            self::markUndelivered($application, JobApplicationCvStatus::Failed);

            return;
        }

        if (! PrivateGroupTarget::verify($client, $chatId)) {
            CareersAlerts::once('refused_target');
            $this->backToPending($application);
            $this->release(600);

            return;
        }

        $message = JobApplicationMessage::render($application);

        try {
            $sent = $client->sendDocument($chatId, $bytes, 'CV-'.$application->id.'.pdf', $message->html, $message->buttons);
        } catch (TelegramException $exception) {
            if (in_array($exception->status, [400, 403], true)) {
                PrivateGroupTarget::invalidate($chatId);
            }

            throw $exception;
        }
        $this->recordForwarded($application, $client, $chatId, $sent);
    }

    /**
     * Post-send bookkeeping. The CV is already in the chat, so nothing here may
     * put the row back to pending (a retry would send it twice) or throw: a
     * database failure is retried once with the minimum and then only logged
     * (the row stays "sending"; the expire command's recovery is the last resort).
     * A row deleted meanwhile (erasure) gets its message removed at once, best effort.
     *
     * @param  array<string, mixed>  $sent
     */
    private function recordForwarded(JobApplication $application, TelegramClient $client, string $chatId, array $sent): void
    {
        $document = is_array($sent['document'] ?? null) ? $sent['document'] : [];
        $messageId = is_numeric($sent['message_id'] ?? null) ? (int) $sent['message_id'] : null;
        $values = [
            'cv_status' => JobApplicationCvStatus::Forwarded->value,
            'cv_file_id' => is_string($document['file_id'] ?? null) ? mb_substr($document['file_id'], 0, 255) : null,
            'cv_file_unique_id' => is_string($document['file_unique_id'] ?? null) ? mb_substr($document['file_unique_id'], 0, 64) : null,
            'telegram_message_id' => $messageId,
            'cv_forwarded_at' => now(),
            'cv_pending_path' => null,
            'updated_at' => now(),
        ];
        $updated = null;

        $full = $values;
        $minimal = ['cv_status' => JobApplicationCvStatus::Forwarded->value, 'telegram_message_id' => $messageId, 'cv_pending_path' => null];

        foreach ([$full, $minimal] as $attempt) {
            try {
                // Never overwrite a final state (expired, failed) with forwarded.
                $updated = JobApplication::query()->whereKey($application->id)
                    ->whereIn('cv_status', [JobApplicationCvStatus::Pending->value, JobApplicationCvStatus::Sending->value])
                    ->update($attempt);

                break;
            } catch (Throwable) {
                Log::warning('Careers CV forward failed', ['job_application' => $application->id, 'code' => 'post_send']);
            }
        }

        // Both updates failed: keep the pending file so the recovery path can't report a false "not delivered".
        if ($updated !== null && ! PendingCv::delete($application->cv_pending_path)) {
            CareersAlerts::once('delete_failed');
        }

        if ($updated === 0) {
            Log::warning('Careers CV forward failed', ['job_application' => $application->id, 'code' => 'row_deleted']);

            if ($messageId !== null) {
                try {
                    $client->deleteMessage($chatId, $messageId);
                } catch (Throwable) {
                    // Best effort: the manual-cleanup procedure covers it.
                }
            }

            return;
        }

        if ($updated === null) {
            return;
        }

        try {
            Activity::query()->create([
                'subject_type' => $application->getMorphClass(),
                'subject_id' => $application->id,
                'type' => ActivityType::Notification,
                'meta' => ['cv' => JobApplicationCvStatus::Forwarded->value],
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            Log::warning('Careers CV forward failed', ['job_application' => $application->id, 'code' => 'activity']);
        }
    }

    private function handleTelegramFailure(TelegramException $exception, JobApplication $application): void
    {
        Log::warning('Careers CV forward failed', ['job_application' => $application->id, 'code' => $exception->code()]);

        if (in_array($exception->status, [400, 403], true)) {
            CareersAlerts::once('send_failed');
            self::markUndelivered($application, JobApplicationCvStatus::Failed);

            return;
        }

        $this->backToPending($application);

        if ($exception->isRateLimited()) {
            $this->release($exception->retryAfter ?? $this->backoff()[0]);

            return;
        }

        throw $exception;
    }

    private function backToPending(JobApplication $application): void
    {
        JobApplication::query()->whereKey($application->id)
            ->where('cv_status', JobApplicationCvStatus::Sending->value)
            ->update(['cv_status' => JobApplicationCvStatus::Pending->value, 'updated_at' => now()]);
    }
}
