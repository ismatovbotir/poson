<?php

namespace App\Actions\Videos;

use App\Models\Video;
use App\Support\Content\VideoOwners;
use App\Support\Content\VideoThumbnails;
use Illuminate\Support\Facades\DB;

/**
 * Delete a video, remove its cached preview when no other row uses the id
 * (after the commit) and touch the owner when the video was public.
 */
class DeleteVideo
{
    public function handle(Video $video): void
    {
        $youtubeId = $video->youtube_id;

        DB::transaction(function () use ($video, $youtubeId): void {
            $wasActive = $video->is_active;
            $video->delete();

            if ($wasActive) {
                $owner = VideoOwners::find($video->videoable_type, (int) $video->videoable_id);
                $owner !== null && VideoOwners::touch($owner);
            }

            DB::afterCommit(fn () => VideoThumbnails::forgetIfUnused($youtubeId));
        });
    }
}
