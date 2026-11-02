<?php

namespace App\Actions\Videos;

use App\Models\Video;
use App\Support\Content\VideoThumbnails;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Remove every video of a deleted owner and the cached previews nobody else
 * uses. No touch: the owner is gone.
 */
class DeleteOwnerVideos
{
    public function handle(Model $owner): void
    {
        $ids = Video::query()->ownedBy($owner)->pluck('youtube_id')->unique()->all();

        if ($ids === []) {
            return;
        }

        Video::query()->ownedBy($owner)->delete();

        DB::afterCommit(function () use ($ids): void {
            foreach ($ids as $id) {
                VideoThumbnails::forgetIfUnused($id);
            }
        });
    }
}
