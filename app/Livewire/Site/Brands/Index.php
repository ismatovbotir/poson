<?php

namespace App\Livewire\Site\Brands;

use App\Models\Brand;
use App\Support\Site\StructuredData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Brands list (decision 2026-10-24) at /{locale}/brands: the active brands
 * with at least one product visible on the site (Brand::scopeListed()), in
 * admin sort order then by name, with logo, name and product count. Two
 * queries (brands with counts, logos). Empty: a short notice and noindex.
 * BreadcrumbList JSON-LD; canonical and hreflang from the layout.
 */
class Index extends Component
{
    public function render(StructuredData $structuredData): View
    {
        $brands = Brand::query()
            ->listed()
            ->withVisibleProductsCount()
            ->with('media')
            ->ordered()
            ->get()
            ->map(fn (Brand $brand): array => [
                'name' => $brand->name,
                'slug' => $brand->slug,
                'url' => route('site.brands.show', ['brand' => $brand->slug]),
                'logo' => $brand->imageUrl('thumb'),
                'count' => (int) $brand->getAttribute('visible_products_count'),
            ])
            ->all();

        $breadcrumbs = [
            ['label' => __('site.breadcrumbs.home'), 'url' => route('site.home')],
            ['label' => __('site.brands.title'), 'url' => null],
        ];

        return view('livewire.site.brands.index', [
            'brands' => $brands,
            'breadcrumbs' => $breadcrumbs,
            'jsonLd' => [StructuredData::toScript($structuredData->breadcrumbs($breadcrumbs, route('site.brands.index')))],
        ])
            ->title(__('site.brands.seo_title'))
            ->layoutData(array_filter([
                'description' => __('site.brands.seo_description'),
                'robots' => $brands === [] ? 'noindex,follow' : null,
            ]));
    }
}
