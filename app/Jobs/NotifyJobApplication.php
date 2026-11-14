<?php

namespace App\Jobs;

use App\Models\JobApplication;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Careers\CareersAlerts;
use App\Support\Careers\CareersCv;
use App\Support\Careers\JobApplicationMessage;
use App\Support\Telegram\PrivateGroupTarget;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Text-only management-chat message of a job application (plan 31, CR-6):
 * no CV, or the CV was not delivered (failed / expired / over a cap). The
 * payload is the id only; the text is rendered at send time from the row, so
 * nothing is sent once the application was deleted. The target is checked
 * with PrivateGroupTarget first (CR-9): a refused target sends nothing and
 * raises the deduped refused_target alert. Retries like SendTelegramMessage
 * (429 → release, 400/401/403/404 → failed, 5xx / timeout → backoff).
 * Logs: id and error code only.
 */
class NotifyJobApplication implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $jobApplicationId, public bool $cvNotDelivered = false) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(TelegramClient $client): void
    {
        $application = JobApplication::query()->with('vacancy:id,title')->find($this->jobApplicationId);

        if ($application === null || ! $client->isConfigured()) {
            return;
        }

        $chatId = CareersCv::chatId();

        if ($chatId === null) {
            if (CareersCv::chatIsSet()) {
                CareersAlerts::once('refused_target');
            }

            return;
        }

        try {
            if (! PrivateGroupTarget::verify($client, $chatId)) {
                CareersAlerts::once('refused_target');

                return;
            }

            $message = JobApplicationMessage::render($application, $this->cvNotDelivered);
            $sent = $client->sendMessage($chatId, $message->html, $message->buttons);

            if (is_numeric($sent['message_id'] ?? null)) {
                JobApplication::query()->whereKey($application->id)->whereNull('telegram_message_id')
                    ->update(['telegram_message_id' => (int) $sent['message_id']]);

                // Erased while the message was being sent: take it back out of the chat.
                if (! JobApplication::query()->whereKey($application->id)->exists()) {
                    Log::warning('Careers application alert failed', ['job_application' => $application->id, 'code' => 'row_deleted']);
                    $this->deleteSent($client, $chatId, (int) $sent['message_id']);
                }
            }
        } catch (TelegramException $exception) {
            if (in_array($exception->status, [400, 403], true)) {
                PrivateGroupTarget::invalidate($chatId);
            }

            Log::warning('Careers application alert failed', ['job_application' => $this->jobApplicationId, 'code' => $exception->code()]);

            if ($exception->isRateLimited()) {
                $this->release($exception->retryAfter ?? $this->backoff()[0]);

                return;
            }

            if ($exception->isPermanent()) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        }
    }

    private function deleteSent(TelegramClient $client, string $chatId, int $messageId): void
    {
        try {
            $client->deleteMessage($chatId, $messageId);
        } catch (TelegramException) {
            // Best effort: the manual-cleanup procedure covers it.
        }
    }
}
