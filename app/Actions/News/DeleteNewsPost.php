<?php

namespace App\Actions\News;

use App\Models\NewsPost;
use App\Support\Media\MediaReplacer;
use Illuminate\Support\Facades\DB;

/**
 * Delete a news post with its cover image (files are removed after the commit)
 * and its product and category links (pivot rows cascade).
 */
class DeleteNewsPost
{
    public function __construct(private MediaReplacer $replacer) {}

    public function handle(NewsPost $post): void
    {
        DB::transaction(function () use ($post): void {
            $this->replacer->clearAll($post);
            $post->deletePreservingMedia();
        });
    }
}
