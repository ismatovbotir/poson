<?php

namespace App\Actions\Banners;

use App\Models\Banner;
use App\Support\Site\BannerDirectory;
use Illuminate\Support\Facades\DB;

/**
 * Move a banner one place up (-1) or down (+1) within its list (the home
 * slider, or the banners of one category); the list is renumbered 1..n in
 * the same transaction and the site cache is flushed after the commit.
 */
class MoveBanner
{
    public function handle(Banner $banner, int $direction): void
    {
        DB::transaction(function () use ($banner, $direction): void {
            $ids = Banner::query()
                ->lockForUpdate()
                ->placedAt($banner->placement)
                ->where('category_id', $banner->category_id)
                ->ordered()
                ->pluck('id')
                ->all();

            $from = array_search($banner->id, $ids, true);
            $to = $from === false ? false : $from + ($direction < 0 ? -1 : 1);

            if ($from === false || $to < 0 || $to >= count($ids)) {
                return;
            }

            [$ids[$from], $ids[$to]] = [$ids[$to], $ids[$from]];

            foreach ($ids as $index => $id) {
                Banner::query()->whereKey($id)->update(['sort' => $index + 1]);
            }

            BannerDirectory::flush();
            DB::afterCommit(fn () => BannerDirectory::flush());
        });
    }
}
