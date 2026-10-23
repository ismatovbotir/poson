<?php

namespace App\Actions\News;

use App\Models\NewsPost;
use Illuminate\Support\Facades\DB;

/**
 * Delete a news post with its cover image (medialibrary removes the files)
 * and its product and category links (pivot rows cascade).
 */
class DeleteNewsPost
{
    public function handle(NewsPost $post): void
    {
        DB::transaction(fn () => $post->delete());
    }
}
