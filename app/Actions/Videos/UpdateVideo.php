<?php

namespace App\Actions\Videos;

use App\Models\Video;
use App\Support\Content\VideoOwners;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edit the manual title, the published date and the active flag of a video.
 * The owner is touched (sitemap, IndexNow) only when something changed and the
 * video is or was public.
 */
class UpdateVideo
{
    public function __construct(private VideoFields $fields) {}

    /**
     * @param  array{title?: mixed, published_on?: mixed, is_active?: bool}  $data
     *
     * @throws ValidationException
     */
    public function handle(Video $video, array $data): Video
    {
        $title = array_key_exists('title', $data) ? $this->fields->titles($data['title']) : null;
        $publishedOn = array_key_exists('published_on', $data) ? $this->fields->publishedOn($data['published_on']) : null;

        return DB::transaction(function () use ($video, $data, $title, $publishedOn): Video {
            $wasActive = $video->is_active;

            if ($title !== null) {
                $video->storeTranslations('title', $title);
            }

            if (array_key_exists('published_on', $data)) {
                $video->published_on = $publishedOn;
            }

            if (array_key_exists('is_active', $data)) {
                $video->is_active = (bool) $data['is_active'];
            }

            if (! $video->isDirty()) {
                return $video;
            }

            $video->save();

            if ($wasActive || $video->is_active) {
                $owner = VideoOwners::find($video->videoable_type, (int) $video->videoable_id);
                $owner !== null && VideoOwners::touch($owner);
            }

            return $video;
        });
    }
}
