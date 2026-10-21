<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductDocument;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public download of an uploaded product document (private disk), only for
 * a product shown on the site. The document must belong to the product
 * (scoped binding). Links (url documents) are opened directly from the
 * product page and never come here.
 */
class DownloadProductDocumentController extends Controller
{
    public function __invoke(Product $product, ProductDocument $document): StreamedResponse
    {
        abort_unless($product->isVisible(), 404);

        $disk = Storage::disk(ProductDocument::DISK);

        abort_if($document->path === null || ! $disk->exists($document->path), 404);

        return $disk->download(
            $document->path,
            $document->original_name ?? basename($document->path),
            [
                'X-Content-Type-Options' => 'nosniff',
                'X-Robots-Tag' => 'noindex',
            ],
        );
    }
}
