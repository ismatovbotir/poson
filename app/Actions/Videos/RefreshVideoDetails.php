<?php

namespace App\Actions\Videos;

use App\Enums\VideoFetchStatus;
use App\Jobs\FetchVideoDetails;
use App\Models\User;
use App\Models\Video;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Queue a fresh metadata fetch for a video. Limited to once a minute per video
 * and ten a minute per user (hit counters, so parallel clicks can't slip by).
 */
class RefreshVideoDetails
{
    public const PER_USER_PER_MINUTE = 10;

    /**
     * @throws ValidationException
     */
    public function handle(Video $video, ?User $user = null): void
    {
        $perVideo = RateLimiter::hit('video-refresh:'.$video->id, 60);
        $perUser = $user === null ? 0 : RateLimiter::hit('video-refresh-user:'.$user->id, 60);

        if ($perVideo > 1 || $perUser > self::PER_USER_PER_MINUTE) {
            throw ValidationException::withMessages(['refresh' => __('admin.videos.errors.refresh_throttled')]);
        }

        Video::query()->whereKey($video->id)->update([
            'fetch_status' => VideoFetchStatus::Pending->value,
            'fetched_at' => null,
            'updated_at' => now(),
        ]);

        dispatch(new FetchVideoDetails($video->id, $video->youtube_id));
    }
}
