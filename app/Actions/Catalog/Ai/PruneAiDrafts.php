<?php

namespace App\Actions\Catalog\Ai;

use App\Enums\AiDraftStatus;
use App\Models\AiProductDraft;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Temp lifecycle of the AI product wizard (plan 32 PW-28, PW-18, plan change 1):
 *  - lost stages (seeing / writing untouched for longer than AiDraftState::staleSeconds()) become failed "error" (M2);
 *  - drafts stuck in applying for more than chatbot.ai.product.applying_stale_hours become failed
 *    "apply_interrupted" (N5: the wizard then offers only "Open product" / "Discard"; their folder,
 *    with the kept/ originals, stays until the draft TTL);
 *  - open drafts past expires_at become expired (a pending stage's job then stores nothing);
 *  - vision/ copies older than chatbot.ai.product.vision_ttl_minutes are deleted;
 *  - the whole folder of an expired draft (cleaned images, previews, kept replaced originals) is deleted;
 *  - folders without a draft row (older than an hour) are deleted;
 *  - rows older than chatbot.ai.product.keep_days are deleted, with their folders.
 * Never touches a draft that is still being applied (within applying_stale_hours).
 */
class PruneAiDrafts
{
    /**
     * @return array{stale: int, expired: int, vision: int, folders: int, orphans: int, rows: int}
     */
    public function handle(): array
    {
        $disk = Storage::disk('local');
        $counts = ['stale' => 0, 'expired' => 0, 'vision' => 0, 'folders' => 0, 'orphans' => 0, 'rows' => 0];

        $counts['stale'] = AiDraftState::failStale();
        $counts['stale'] += AiProductDraft::query()
            ->where('status', AiDraftStatus::Applying->value)
            ->where('updated_at', '<', now()->subHours(max(1, (int) config('chatbot.ai.product.applying_stale_hours', 2))))
            ->update(['status' => AiDraftStatus::Failed->value, 'error' => 'apply_interrupted', 'updated_at' => now()]);

        $counts['expired'] = AiProductDraft::query()
            ->where('expires_at', '<', now())
            ->whereNotIn('status', AiDraftStatus::values([AiDraftStatus::Expired, AiDraftStatus::Applied, AiDraftStatus::Applying]))
            ->update(['status' => AiDraftStatus::Expired->value, 'updated_at' => now()]);

        $visionBefore = now()->subMinutes((int) config('chatbot.ai.product.vision_ttl_minutes', 60))->getTimestamp();
        $orphanBefore = now()->subHour()->getTimestamp();
        $folders = $disk->directories('ai-drafts');
        $uuids = array_values(array_filter(array_map('basename', $folders), fn (string $name): bool => Str::isUuid($name)));

        $rows = AiProductDraft::query()->whereIn('uuid', $uuids)->get(['uuid', 'status', 'expires_at'])->keyBy('uuid');

        foreach ($folders as $folder) {
            $uuid = basename($folder);
            $draft = $rows->get($uuid);

            if ($draft === null) {
                if (! Str::isUuid($uuid) || $this->modified($folder) < $orphanBefore) {
                    $disk->deleteDirectory($folder);
                    $counts['orphans']++;
                }

                continue;
            }

            if ($draft->status !== AiDraftStatus::Applying && $draft->expires_at->isPast()) {
                $disk->deleteDirectory($folder);
                $counts['folders']++;

                continue;
            }

            $vision = $folder.'/vision';

            if ($disk->directoryExists($vision) && $this->modified($vision) < $visionBefore) {
                $disk->deleteDirectory($vision);
                $counts['vision']++;
            }
        }

        AiProductDraft::query()
            ->where('created_at', '<', now()->subDays((int) config('chatbot.ai.product.keep_days', 7)))
            ->where('status', '!=', AiDraftStatus::Applying->value)
            ->select(['id', 'uuid'])
            ->chunkById(200, function ($drafts) use ($disk, &$counts): void {
                foreach ($drafts as $draft) {
                    $disk->deleteDirectory(AiProductDraft::folder($draft->uuid));
                }

                $counts['rows'] += AiProductDraft::query()->whereKey($drafts->modelKeys())->delete();
            });

        return $counts;
    }

    /**
     * Newest modification time inside a folder (the folder itself when empty).
     */
    private function modified(string $folder): int
    {
        $disk = Storage::disk('local');
        $newest = 0;

        foreach ($disk->allFiles($folder) as $file) {
            $newest = max($newest, (int) $disk->lastModified($file));
        }

        if ($newest === 0) {
            $path = $disk->path($folder);
            $newest = is_dir($path) ? (int) filemtime($path) : 0;
        }

        return $newest;
    }
}
