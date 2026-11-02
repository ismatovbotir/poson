<?php

namespace App\Support\Site;

use App\Models\Video;
use Illuminate\Database\Eloquent\Model;

/**
 * Videos shown on a public page (plan 19), one query per page: the owner's
 * visible (active, not unavailable) videos in order, at most
 * Video::MAX_PER_OWNER. Titles come from Video::displayTitle() (manual, else the
 * oEmbed title when it is brand safe, else "{owner} — Video"); thumbnails are
 * local files or null (the facade then shows a placeholder).
 *
 * @phpstan-type VideoView array{id: int, title: string, embed: string, watch: string, thumbnail: ?string, model: Video}
 */
class VideoContent
{
    /**
     * @return list<VideoView>
     */
    public function forOwner(Model $owner, string $ownerName): array
    {
        $fallback = __('site.videos.fallback_title');

        return Video::query()
            ->ownedBy($owner)
            ->visible()
            ->ordered()
            ->limit(Video::MAX_PER_OWNER)
            ->get()
            ->map(fn (Video $video): array => [
                'id' => $video->id,
                'title' => $video->displayTitle() ?? ($ownerName !== '' ? $ownerName.' — '.$fallback : $fallback),
                'embed' => $video->embedUrl(),
                'watch' => $video->watchUrl(),
                'thumbnail' => $video->thumbnailUrl(),
                'model' => $video,
            ])
            ->values()
            ->all();
    }
}
