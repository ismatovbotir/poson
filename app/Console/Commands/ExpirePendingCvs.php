<?php

namespace App\Console\Commands;

use App\Enums\JobApplicationCvStatus;
use App\Jobs\ForwardJobApplicationCv;
use App\Models\JobApplication;
use App\Support\Careers\CareersAlerts;
use App\Support\Careers\CareersCaps;
use App\Support\Careers\PendingCv;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Hourly clean-up of the careers CV queue (plan 31, CR-5; routes/console.php).
 */
#[Signature('careers:expire-pending-cvs')]
#[Description('Expire careers CVs that waited too long for Telegram, recover stuck sends and delete orphan files')]
class ExpirePendingCvs extends Command
{
    public const STUCK_MINUTES = 15;

    /**
     * Above the longest job backoff (3600 s): a "pending" row older than this has no job waiting.
     */
    public const REQUEUE_MINUTES = 70;

    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Looks after the encrypted CV copies that wait to be forwarded to the Telegram management chat.
1) Applications stuck in "sending" for more than 15 minutes (a worker died) go back to "pending" and are queued again.
2) CVs still waiting after careers.pending_cv_hours (24 h): the encrypted file is deleted, the CV is marked "expired" and a text-only message is sent; one counts-only alert.
3) Applications still "pending" for more than 70 minutes (longer than the longest retry wait; for example the chat was refused for a while) are queued again, each at most once per 70 minutes.
4) Files in storage/app/private/careers/pending older than 24 h that no application points to are deleted.
Runs by itself every hour; a manual run is safe at any time. Side effects: deletes files, changes CV states, sends Telegram messages.';

    public function handle(): int
    {
        $hours = (int) config('careers.pending_cv_hours', 24);
        $cutoff = now()->subHours($hours);

        $recovered = $this->recoverStuck($cutoff);
        $requeued = $this->requeuePending($cutoff);
        $expired = $this->expire($cutoff);
        $orphans = $this->sweepOrphans($cutoff);

        if ($expired > 0) {
            CareersAlerts::once('expired', $expired);
        }

        $this->components->info("{$recovered} stuck send(s) recovered, {$requeued} waiting CV(s) queued again, {$expired} CV(s) expired, {$orphans} orphan file(s) deleted.");

        return self::SUCCESS;
    }

    private function recoverStuck(\DateTimeInterface $cutoff): int
    {
        $count = 0;

        JobApplication::query()
            ->where('cv_status', JobApplicationCvStatus::Sending->value)
            ->where('updated_at', '<', now()->subMinutes(self::STUCK_MINUTES))
            ->where('created_at', '>=', $cutoff)
            ->select(['id'])
            ->lazyById(100)
            ->each(function (JobApplication $application) use (&$count): void {
                $reset = JobApplication::query()->whereKey($application->id)
                    ->where('cv_status', JobApplicationCvStatus::Sending->value)
                    ->update(['cv_status' => JobApplicationCvStatus::Pending->value, 'updated_at' => now()]);

                if ($reset > 0) {
                    ForwardJobApplicationCv::dispatch($application->id);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * "pending" rows nobody is working on (a released job is gone): queue them again.
     */
    private function requeuePending(\DateTimeInterface $cutoff): int
    {
        $count = 0;

        JobApplication::query()
            ->where('cv_status', JobApplicationCvStatus::Pending->value)
            ->where('updated_at', '<', now()->subMinutes(self::REQUEUE_MINUTES))
            ->where('created_at', '>=', $cutoff)
            ->select(['id'])
            ->lazyById(100)
            ->each(function (JobApplication $application) use (&$count): void {
                // Touch first: the same row is not picked again for another REQUEUE_MINUTES.
                $touched = JobApplication::query()->whereKey($application->id)
                    ->where('cv_status', JobApplicationCvStatus::Pending->value)
                    ->update(['updated_at' => now()]);

                if ($touched > 0) {
                    ForwardJobApplicationCv::dispatch($application->id);
                    $count++;
                }
            });

        return $count;
    }

    private function expire(\DateTimeInterface $cutoff): int
    {
        $count = 0;

        JobApplication::query()
            ->whereIn('cv_status', [JobApplicationCvStatus::Pending->value, JobApplicationCvStatus::Sending->value])
            ->where('created_at', '<', $cutoff)
            ->select(['id', 'cv_status', 'cv_pending_path'])
            ->lazyById(100)
            ->each(function (JobApplication $application) use (&$count): void {
                ForwardJobApplicationCv::markUndelivered($application, JobApplicationCvStatus::Expired);
                $count++;
            });

        return $count;
    }

    private function sweepOrphans(\DateTimeInterface $cutoff): int
    {
        $count = 0;
        $failed = 0;

        try {
            $files = PendingCv::disk()->files(CareersCaps::PENDING_DIR);
        } catch (Throwable $exception) {
            report($exception);

            return 0;
        }

        foreach ($files as $path) {
            try {
                if (PendingCv::disk()->lastModified($path) >= $cutoff->getTimestamp()) {
                    continue;
                }
            } catch (Throwable) {
                continue;
            }

            if (JobApplication::query()->where('cv_pending_path', $path)->exists()) {
                continue;
            }

            try {
                PendingCv::disk()->delete($path) ? $count++ : $failed++;
            } catch (Throwable) {
                $failed++;
            }
        }

        if ($failed > 0) {
            CareersAlerts::once('delete_failed', $failed);
        }

        return $count;
    }
}
