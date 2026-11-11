<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use App\Models\NewsPost;
use App\Models\Page;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff preview of an image original (private disk) for admin screens when
 * its conversion is missing. Strict allow-list of owner models and
 * collections; stamp, signature and UzQR media (and anything else) are 404.
 * The owner's "view" policy (or settings.company for the company logo/OG
 * image) decides access. Never used by public pages.
 */
class ShowMediaOriginalController extends Controller
{
    public function __invoke(Media $media): Response
    {
        $owner = $media->model()->withTrashed()->first();

        abort_unless($owner instanceof Model && $this->isAllowed($owner, $media->collection_name), 404);

        if ($owner instanceof Setting) {
            Gate::authorize('manageCompany', Setting::class);
        } else {
            Gate::authorize('view', $owner);
        }

        abort_unless(in_array($media->mime_type, Product::IMAGE_MIMES, true), 404);

        $disk = Storage::disk($media->disk);
        $path = $media->getPathRelativeToRoot();

        abort_unless($disk->exists($path), 404);

        $response = $disk->response($path, null, ['Content-Type' => $media->mime_type]);
        $response->headers->set('Content-Disposition', 'inline');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function isAllowed(Model $owner, string $collection): bool
    {
        return match (true) {
            $owner instanceof Product => $collection === Product::IMAGES,
            $owner instanceof Banner => in_array($collection, [Banner::DESKTOP, Banner::MOBILE], true),
            $owner instanceof Setting => in_array($collection, array_column(Setting::MEDIA, 'collection'), true),
            $owner instanceof Brand, $owner instanceof Category, $owner instanceof Page, $owner instanceof NewsPost => $collection === $owner->imageCollection(),
            default => false,
        };
    }
}
