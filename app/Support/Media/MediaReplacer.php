<?php

namespace App\Support\Media;

use App\Support\Site\HomeCache;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Rollback-safe replacing and deleting of medialibrary media (MC-2).
 *
 * Inside the caller's transaction only rows change: old rows go with a
 * query-builder delete on exact ids scoped by model and collection (no model
 * events, so no file is touched). The old files (originals and conversions,
 * both disks) are removed after the commit. If adding the new media fails,
 * the files of the new media are removed before the exception is rethrown.
 * Media added here are remembered, so a caller whose transaction fails later
 * can call discardCreated() to remove their files.
 *
 * Bound as a scoped singleton: SaveBanner and UpdateCatalogImage share it.
 */
class MediaReplacer
{
    /**
     * @var list<Media>
     */
    private array $created = [];

    public function __construct(private Filesystem $files) {}

    /**
     * Run $add (which adds the new media) and drop the previous media of the collection.
     *
     * @param  Closure(): mixed  $add
     *
     * @throws Throwable
     */
    public function replace(Model $model, string $collection, Closure $add): void
    {
        $this->lock($model);

        $before = $this->collection($model, $collection)->pluck('id')->all();

        try {
            $add();
        } catch (Throwable $exception) {
            $this->purge($this->collection($model, $collection)->reject(fn (Media $media): bool => in_array($media->id, $before, true)));

            throw $exception;
        }

        $all = $this->collection($model, $collection);
        $new = $all->reject(fn (Media $media): bool => in_array($media->id, $before, true));

        $this->created = [...$this->created, ...$new->all()];

        // Once committed, nothing created here may ever be discarded again.
        DB::afterCommit(function (): void {
            $this->created = [];
        });

        // Everything else in the collection goes, also rows a concurrent save added meanwhile.
        $this->forget($all->reject(fn (Media $media): bool => $new->contains('id', $media->id)));
        $this->unload($model);

        // After the old files are queued for removal: one bump covers the whole replace.
        HomeCache::bump();
    }

    /**
     * Remove the media of one collection.
     */
    public function clear(Model $model, string $collection): void
    {
        $this->forget($this->collection($model, $collection));
        $this->unload($model);
    }

    /**
     * Remove every media item of the model (before the model itself is deleted).
     */
    public function clearAll(Model $model): void
    {
        $this->forget($this->query($model)->get());
        $this->unload($model);
    }

    /**
     * Run $callback (normally a DB transaction). If it throws, the media added
     * by replace() meanwhile are removed (rows and files) before the rethrow.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     *
     * @throws Throwable
     */
    public function guard(Closure $callback): mixed
    {
        $outer = $this->created;
        $this->created = [];

        try {
            $result = $callback();
        } catch (Throwable $exception) {
            try {
                $this->discardCreated();
            } catch (Throwable $discardFailure) {
                report($discardFailure);
            }

            $this->created = $outer;

            throw $exception;
        }

        $this->created = [...$outer, ...$this->created];

        return $result;
    }

    /**
     * Remove the files of media added by replace() whose rows no longer exist
     * (the transaction rolled back). Committed media are never touched, even
     * when an after-commit callback threw.
     */
    public function discardCreated(): void
    {
        $created = collect($this->created);
        $this->created = [];

        if ($created->isEmpty()) {
            return;
        }

        $existing = Media::query()->whereKey($created->pluck('id')->all())->pluck('id')->all();

        $this->purge($created->reject(fn (Media $media): bool => in_array($media->id, $existing, true)));
    }

    /**
     * @param  Collection<int, Media>  $media
     */
    public function forget(Collection $media): void
    {
        if ($media->isEmpty()) {
            return;
        }

        $first = $media->first();

        Media::query()
            ->where('model_type', $first->model_type)
            ->where('model_id', $first->model_id)
            ->whereIn('collection_name', $media->pluck('collection_name')->unique()->all())
            ->whereKey($media->pluck('id')->all())
            ->delete();

        DB::afterCommit(function () use ($media): void {
            foreach ($media as $item) {
                try {
                    $this->files->removeAllFiles($item);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        });
    }

    /**
     * @param  Collection<int, Media>  $media
     */
    private function purge(Collection $media): void
    {
        foreach ($media as $item) {
            try {
                $this->files->removeAllFiles($item);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if ($media->isNotEmpty()) {
            Media::query()->whereKey($media->pluck('id')->all())->delete();
        }
    }

    /**
     * @return Collection<int, Media>
     */
    private function collection(Model $model, string $collection): Collection
    {
        $query = $this->query($model)->where('collection_name', $collection);

        // Fresh read under the owner lock (MySQL REPEATABLE READ would give a stale snapshot).
        // SQLite ignores row locks, so this part cannot be tested there.
        return DB::transactionLevel() > 0 ? $query->lockForUpdate()->get() : $query->get();
    }

    private function query(Model $model): Builder
    {
        return Media::query()
            ->where('model_type', $model->getMorphClass())
            ->where('model_id', $model->getKey())
            ->orderBy('id');
    }

    private function lock(Model $model): void
    {
        if ($model->exists && DB::transactionLevel() > 0) {
            $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->first();
        }
    }

    private function unload(Model $model): void
    {
        $model->unsetRelation('media');
    }
}
