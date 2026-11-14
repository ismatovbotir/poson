<?php

namespace App\Actions\Careers;

use App\Models\JobApplication;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Careers\CareersAlerts;
use App\Support\Careers\CareersCv;
use App\Support\Careers\PendingCv;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Erase a job application (plan 31, §17.4 / CR-13): the row, its
 * activities and the encrypted pending CV file. Best effort afterwards: the
 * management-chat message is deleted when it is younger than 48 hours
 * (Telegram allows bots no later delete); older copies are removed by hand
 * (see the help text on the applications screen). A queued forward or
 * alert finds no row and sends nothing.
 */
class DeleteJobApplication
{
    public const TELEGRAM_DELETE_HOURS = 48;

    public function __construct(private readonly TelegramClient $client) {}

    public function handle(JobApplication $application, User $user): void
    {
        Gate::forUser($user)->authorize('delete', $application);

        $messageId = null;
        $sentAt = null;
        $pendingPath = null;
        $id = $application->id;

        DB::transaction(function () use ($id, &$messageId, &$sentAt, &$pendingPath): void {
            $current = JobApplication::query()->whereKey($id)->lockForUpdate()->first();

            if ($current === null) {
                return;
            }

            $messageId = $current->telegram_message_id;
            $sentAt = $current->cv_forwarded_at ?? $current->created_at;
            $pendingPath = $current->cv_pending_path;

            $current->activities()->delete();
            $current->delete();
        });

        if (! PendingCv::delete($pendingPath)) {
            CareersAlerts::once('delete_failed');
        }

        $chatId = CareersCv::chatId();

        if ($messageId !== null && $chatId !== null && $sentAt !== null && $sentAt->gt(now()->subHours(self::TELEGRAM_DELETE_HOURS)) && $this->client->isConfigured()) {
            try {
                $this->client->withTimeout(5, 3)->deleteMessage($chatId, $messageId);
            } catch (TelegramException $exception) {
                Log::info('Careers Telegram copy not deleted', ['job_application' => $id, 'code' => $exception->code()]);
            }
        }
    }
}
