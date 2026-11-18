<?php

namespace App\Support\Catalog;

use App\Enums\ProductType;
use App\Enums\RepairDeviceType;
use App\Models\Product;

/**
 * Plan 36: which hardware products can be sent to the service centre
 * ("Service for this device", prefilled service request). Hardware only;
 * the product's category and its ancestors are walked nearest first, using
 * the cached CategoryTree. The first category that is excluded
 * (config site.service_device_excluded) or mapped
 * (site.service_device_categories, slug → RepairDeviceType value) decides.
 * Unknown config values are dropped, never guessed.
 */
final class ServiceableDevice
{
    public static function for(Product $product): ?RepairDeviceType
    {
        if ($product->type !== ProductType::Hardware || $product->category_id === null) {
            return null;
        }

        $excluded = (array) config('site.service_device_excluded', []);
        $map = (array) config('site.service_device_categories', []);
        $nodes = CategoryTree::nodes();

        foreach ([$product->category_id, ...CategoryTree::ancestorIds($product->category_id)] as $id) {
            $slug = $nodes[$id]['slug'] ?? null;

            if ($slug === null) {
                continue;
            }

            if (in_array($slug, $excluded, true)) {
                return null;
            }

            if (isset($map[$slug])) {
                $type = RepairDeviceType::tryFrom((string) $map[$slug]);

                if ($type !== null && $type !== RepairDeviceType::Other) {
                    return $type;
                }
            }
        }

        return null;
    }
}
