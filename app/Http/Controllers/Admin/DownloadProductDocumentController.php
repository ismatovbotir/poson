<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductDocument;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Staff download of an uploaded product document (private disk). Allowed to
 * anyone who may view the product, also while it is in the trash. Links
 * (url documents) are opened directly and never come here.
 */
class DownloadProductDocumentController extends Controller
{
    public function __invoke(ProductDocument $document): StreamedResponse
    {
        $product = Product::withTrashed()->findOrFail($document->product_id);
        Gate::authorize('view', $product);

        abort_if($document->path === null || ! Storage::disk(ProductDocument::DISK)->exists($document->path), 404);

        return Storage::disk(ProductDocument::DISK)->download(
            $document->path,
            $document->original_name ?? basename($document->path),
        );
    }
}
