<?php

namespace App\Actions\Banners;

use App\Models\Banner;
use App\Support\Media\MediaReplacer;
use Illuminate\Support\Facades\DB;

/**
 * Delete a banner with its images (rows in the transaction, files after the commit).
 */
class DeleteBanner
{
    public function __construct(private MediaReplacer $replacer) {}

    public function handle(Banner $banner): void
    {
        DB::transaction(function () use ($banner): void {
            $this->replacer->clearAll($banner);
            $banner->deletePreservingMedia();
        });
    }
}
