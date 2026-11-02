<?php

namespace App\Actions\Videos;

use App\Enums\VideoFetchStatus;
use App\Jobs\FetchVideoDetails;
use App\Models\Video;
use App\Support\Content\VideoOwners;
use App\Support\Content\VideoThumbnails;
use App\Support\Content\YoutubeVideo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Attach a YouTube video (pasted link or id) to a product, category, brand or
 * news post (plan 19). Error messages never echo the pasted text. The owner row
 * is locked before counting, so parallel adds can't pass the cap of
 * Video::MAX_PER_OWNER; the unique index backs the duplicate check. Metadata
 * is fetched later by a queued job (no outbound HTTP here). The owner is
 * touched only when the video is public (active).
 */
class AttachVideo
{
    public function __construct(private VideoFields $fields) {}

    /**
     * @param  array{title?: mixed, published_on?: mixed}  $data
     *
     * @throws ValidationException
     */
    public function handle(Model $owner, mixed $input, array $data = [], bool $active = true): Video
    {
        if (! VideoOwners::allows($owner)) {
            throw ValidationException::withMessages(['url' => __('admin.videos.errors.not_found')]);
        }

        $youtubeId = YoutubeVideo::parse($input);

        if ($youtubeId === null) {
            throw ValidationException::withMessages(['url' => __('admin.videos.errors.invalid_link')]);
        }

        $title = $this->fields->titles($data['title'] ?? null);
        $publishedOn = $this->fields->publishedOn($data['published_on'] ?? null);

        try {
            $video = DB::transaction(fn (): Video => $this->create($owner, $youtubeId, $title, $publishedOn, $active));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['url' => __('admin.videos.errors.duplicate')]);
        }

        return $video;
    }

    /**
     * @param  array<string, string>  $title
     */
    private function create(Model $owner, string $youtubeId, array $title, ?string $publishedOn, bool $active): Video
    {
        $query = $owner::query();

        if (in_array(SoftDeletes::class, class_uses_recursive($owner), true)) {
            $query->withTrashed();
        }

        $locked = $query->whereKey($owner->getKey())->lockForUpdate()->first();

        if ($locked === null) {
            throw ValidationException::withMessages(['url' => __('admin.videos.errors.not_found')]);
        }

        $siblings = Video::query()->ownedBy($locked);

        if ((clone $siblings)->count() >= Video::MAX_PER_OWNER) {
            throw ValidationException::withMessages(['url' => __('admin.videos.errors.limit', ['max' => Video::MAX_PER_OWNER])]);
        }

        if ((clone $siblings)->where('youtube_id', $youtubeId)->exists()) {
            throw ValidationException::withMessages(['url' => __('admin.videos.errors.duplicate')]);
        }

        $video = new Video([
            'videoable_type' => $locked->getMorphClass(),
            'videoable_id' => $locked->getKey(),
            'youtube_id' => $youtubeId,
            'published_on' => $publishedOn,
            'fetch_status' => VideoFetchStatus::Pending,
            'sort' => (int) (clone $siblings)->max('sort') + 1,
            'is_active' => $active,
            'thumbnail_at' => VideoThumbnails::exists($youtubeId) ? now() : null,
        ]);
        $video->storeTranslations('title', $title);
        $video->save();

        dispatch(new FetchVideoDetails($video->id, $youtubeId));

        if ($active) {
            $locked->touch();
        }

        return $video;
    }
}
