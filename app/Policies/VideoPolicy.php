<?php

namespace App\Policies;

use App\Models\Brand;
use App\Models\Category;
use App\Models\NewsPost;
use App\Models\Product;
use App\Models\User;
use App\Models\Video;
use App\Support\Content\VideoOwners;
use Illuminate\Database\Eloquent\Model;

/**
 * Video access follows the owner (plan 19), by permission only, no new
 * permission strings: viewing = the owner's view, adding / editing / moving /
 * deleting = the owner's update (a product also covers products.manage-services
 * for software and services). Same semantics as FaqItemPolicy.
 *
 * Usage: authorize('viewAny', [Video::class, $owner]),
 * authorize('create', [Video::class, $owner]), authorize('update', $video).
 */
class VideoPolicy
{
    public function viewAny(User $user, ?Model $owner = null): bool
    {
        return $this->ownerAllowed($owner) && $user->can('view', $owner);
    }

    public function create(User $user, ?Model $owner = null): bool
    {
        return $this->manage($user, $owner);
    }

    public function update(User $user, Video $video): bool
    {
        return $this->manage($user, $this->ownerOf($video));
    }

    public function delete(User $user, Video $video): bool
    {
        return $this->update($user, $video);
    }

    /**
     * Whether the user may add, edit, reorder or delete the videos of an owner.
     */
    public function manage(User $user, ?Model $owner = null): bool
    {
        return $this->ownerAllowed($owner) && $user->can('update', $owner);
    }

    private function ownerAllowed(?Model $owner): bool
    {
        return $owner instanceof Product
            || $owner instanceof Category
            || $owner instanceof Brand
            || $owner instanceof NewsPost;
    }

    /**
     * The owner model (trashed products included), or null when it is gone.
     */
    private function ownerOf(Video $video): ?Model
    {
        return VideoOwners::find($video->videoable_type, (int) $video->videoable_id);
    }
}
