<?php

namespace App\Actions\Banners;

use App\Models\Banner;
use Illuminate\Support\Facades\DB;

/**
 * Delete a banner with its images (medialibrary removes the files).
 */
class DeleteBanner
{
    public function handle(Banner $banner): void
    {
        DB::transaction(fn () => $banner->delete());
    }
}
