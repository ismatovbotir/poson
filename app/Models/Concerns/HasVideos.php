<?php

namespace App\Models\Concerns;

use App\Actions\Videos\DeleteOwnerVideos;
use App\Models\Video;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Videos of a product, category, brand or news post (plan 19). Deleting the
 * owner removes its videos (a product only on force delete: a soft-deleted
 * product keeps them).
 */
trait HasVideos
{
    protected static function bootHasVideos(): void
    {
        $cleanup = fn (self $owner) => app(DeleteOwnerVideos::class)->handle($owner);

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::forceDeleted($cleanup);

            return;
        }

        static::deleted($cleanup);
    }

    /**
     * @return MorphMany<Video, $this>
     */
    public function videos(): MorphMany
    {
        return $this->morphMany(Video::class, 'videoable')->orderBy('sort')->orderBy('id');
    }
}
