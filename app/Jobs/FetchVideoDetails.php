<?php

namespace App\Jobs;

use App\Enums\VideoFetchStatus;
use App\Models\Video;
use App\Rules\BrandSafeText;
use App\Support\Content\VideoOwners;
use App\Support\Content\VideoText;
use App\Support\Content\VideoThumbnails;
use App\Support\Content\YoutubeVideo;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Fetches the title, channel and preview image of a YouTube video (plan 19),
 * for every video row that uses the id (one job per youtube_id at a time).
 * Only two fixed endpoints are ever requested, never a pasted URL; redirects
 * are not followed; bodies are read with a byte cap. Logs carry the status and
 * the row count only, never titles, URLs or bodies.
 */
class FetchVideoDetails implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public const OEMBED_URL = 'https://www.youtube.com/oembed';

    public const THUMBNAIL_HOST = 'https://i.ytimg.com/vi';

    public const OEMBED_MAX_BYTES = 65536;

    public const THUMBNAIL_MAX_BYTES = 2097152;

    public int $tries = 4;

    public int $timeout = 30;

    public int $uniqueFor = 7200;

    public function __construct(public int $videoId, public string $youtubeId)
    {
        YoutubeVideo::assertId($youtubeId);
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->youtubeId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 1800];
    }

    public function handle(): void
    {
        $id = YoutubeVideo::assertId($this->youtubeId);
        $rows = Video::query()->where('youtube_id', $id)->get();

        if ($rows->isEmpty()) {
            return;
        }

        $before = $rows->mapWithKeys(fn (Video $video): array => [$video->id => $this->snapshot($video)])->all();

        $meta = $this->fetchMetadata($id);

        if ($meta['status'] === VideoFetchStatus::Ok) {
            $title = VideoText::clean($meta['title'], Video::TITLE_MAX);
            $author = VideoText::clean($meta['author'], 100);

            Video::query()->where('youtube_id', $id)->update([
                'source_title' => $title,
                'source_author' => $author,
                'source_title_safe' => $title === null || ! BrandSafeText::violates($title),
                'fetch_status' => VideoFetchStatus::Ok->value,
                'fetched_at' => now(),
            ]);
        } else {
            Video::query()->where('youtube_id', $id)->update([
                'fetch_status' => VideoFetchStatus::Unavailable->value,
                'fetched_at' => now(),
            ]);
        }

        if ($meta['status'] !== VideoFetchStatus::Unavailable) {
            $this->cacheThumbnail($id);
        }

        $this->touchChangedOwners($id, $before);

        Log::info('Video details fetched', ['status' => $meta['status']->value, 'rows' => $rows->count()]);
    }

    public function failed(?Throwable $exception): void
    {
        Video::query()
            ->where('youtube_id', $this->youtubeId)
            ->where('fetch_status', VideoFetchStatus::Pending->value)
            ->update(['fetch_status' => VideoFetchStatus::Failed->value, 'fetched_at' => now()]);

        Log::warning('Video details fetch failed', ['video' => $this->videoId]);
    }

    /**
     * @return array{status: VideoFetchStatus, title: mixed, author: mixed}
     */
    private function fetchMetadata(string $id): array
    {
        $result = $this->request(
            Http::connectTimeout(3)->timeout(6),
            self::OEMBED_URL,
            ['url' => YoutubeVideo::watchUrl($id), 'format' => 'json'],
            self::OEMBED_MAX_BYTES,
        );

        if ($result['status'] === 200 && $result['body'] !== null) {
            $data = json_decode($result['body'], true);

            if (is_array($data)) {
                return ['status' => VideoFetchStatus::Ok, 'title' => $data['title'] ?? null, 'author' => $data['author_name'] ?? null];
            }
        }

        if (in_array($result['status'], [400, 401, 403, 404], true)) {
            return ['status' => VideoFetchStatus::Unavailable, 'title' => null, 'author' => null];
        }

        throw new RuntimeException('oEmbed request failed.');
    }

    private function cacheThumbnail(string $id): void
    {
        if (VideoThumbnails::exists($id)) {
            $this->markThumbnail($id);

            return;
        }

        foreach (['maxresdefault', 'hqdefault'] as $name) {
            try {
                $result = $this->request(
                    Http::connectTimeout(3)->timeout(10),
                    self::THUMBNAIL_HOST.'/'.$id.'/'.$name.'.jpg',
                    [],
                    self::THUMBNAIL_MAX_BYTES,
                );
            } catch (ConnectionException) {
                return;
            }

            if ($result['status'] === 200 && $result['body'] !== null && VideoThumbnails::store($id, $result['body'])) {
                $this->markThumbnail($id);

                return;
            }
        }
    }

    private function markThumbnail(string $id): void
    {
        Video::query()->where('youtube_id', $id)->update(['thumbnail_at' => now()]);
    }

    /**
     * One GET with redirects off, reading at most $cap bytes (a larger body or
     * Content-Length yields a null body).
     *
     * @param  array<string, string>  $query
     * @return array{status: int, body: string|null}
     */
    private function request(PendingRequest $http, string $url, array $query, int $cap): array
    {
        $response = $http->withOptions(['allow_redirects' => false, 'stream' => true])->get($url, $query);
        $status = $response->status();

        if ($status !== 200) {
            return ['status' => $status, 'body' => null];
        }

        $length = $response->header('Content-Length');

        if ($length !== '' && is_numeric($length) && (int) $length > $cap) {
            return ['status' => $status, 'body' => null];
        }

        $stream = $response->toPsrResponse()->getBody();
        $body = '';

        while (! $stream->eof()) {
            $body .= $stream->read(8192);

            if (strlen($body) > $cap) {
                return ['status' => $status, 'body' => null];
            }
        }

        return ['status' => $status, 'body' => $body];
    }

    /**
     * @return array{visible: bool, title: ?string, safe: bool, thumbnail: bool}
     */
    private function snapshot(Video $video): array
    {
        return [
            'visible' => $video->isVisible(),
            'title' => $video->source_title,
            'safe' => $video->source_title_safe,
            'thumbnail' => $video->thumbnail_at !== null,
        ];
    }

    /**
     * Touch each distinct owner of an active row whose public output changed.
     *
     * @param  array<int, array{visible: bool, title: ?string, safe: bool, thumbnail: bool}>  $before
     */
    private function touchChangedOwners(string $id, array $before): void
    {
        $owners = [];

        foreach (Video::query()->where('youtube_id', $id)->get() as $video) {
            if (! $video->is_active || ($before[$video->id] ?? null) === $this->snapshot($video)) {
                continue;
            }

            $owners[$video->videoable_type.':'.$video->videoable_id] = [$video->videoable_type, (int) $video->videoable_id];
        }

        foreach ($owners as [$type, $ownerId]) {
            $owner = VideoOwners::find($type, $ownerId);

            if ($owner !== null) {
                VideoOwners::touch($owner);
            }
        }
    }
}
