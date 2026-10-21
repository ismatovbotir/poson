<?php

namespace App\Actions\Catalog;

use App\Models\ProductDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Delete a product document; its file goes after the commit.
 */
class DeleteProductDocument
{
    public function handle(ProductDocument $document): void
    {
        DB::transaction(function () use ($document): void {
            $path = $document->path;
            $document->delete();

            if ($path !== null) {
                DB::afterCommit(fn () => Storage::disk(ProductDocument::DISK)->delete($path));
            }
        });
    }
}
