<?php

namespace App\Actions\Videos;

use App\Models\Video;
use App\Support\Content\VideoOwners;
use Illuminate\Support\Facades\DB;

/**
 * Move a video one place up (-1) or down (+1) within its owner's list; the
 * list is renumbered 1..n in the same transaction.
 */
class MoveVideo
{
    public function handle(Video $video, int $direction): void
    {
        DB::transaction(function () use ($video, $direction): void {
            $ids = Video::query()
                ->where('videoable_type', $video->videoable_type)
                ->where('videoable_id', $video->videoable_id)
                ->lockForUpdate()
                ->ordered()
                ->pluck('id')
                ->all();
            $from = array_search($video->id, $ids, true);
            $to = $from === false ? false : $from + ($direction < 0 ? -1 : 1);

            if ($from === false || $to < 0 || $to >= count($ids)) {
                return;
            }

            [$ids[$from], $ids[$to]] = [$ids[$to], $ids[$from]];

            foreach ($ids as $index => $id) {
                Video::query()->whereKey($id)->update(['sort' => $index + 1]);
            }

            if ($video->is_active) {
                $owner = VideoOwners::find($video->videoable_type, (int) $video->videoable_id);
                $owner !== null && VideoOwners::touch($owner);
            }
        });
    }
}
