<?php

namespace App\Livewire\Site;

use App\Data\Site\BannerSlide;
use App\Data\Site\BrandItem;
use App\Data\Site\CategoryTile;
use App\Data\Site\NewsCard;
use App\Data\Site\ProductCard;
use App\Data\Site\ServiceItem;
use App\Support\CompanySettings;
use App\Support\Site\BannerDirectory;
use App\Support\Site\FaqContent;
use App\Support\Site\HomeCache;
use App\Support\Site\HomeContent;
use App\Support\Site\NewsCards;
use App\Support\Site\StructuredData;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Throwable;

/**
 * Home page (/{locale}): hero, the banner slider (plan 07 7c), categories, popular products, B2B, why us,
 * services, brands, the latest news (plan 07 7b), the general FAQ (with
 * FAQPage JSON-LD) and the lead form. Sections without content are hidden.
 */
class Home extends Component
{
    /**
     * The data comes from the per-locale home cache (plan 34): plain arrays,
     * turned back into DTOs here. JSON-LD is encoded here, from the cached
     * arrays (HC-8); the lead form, chat launcher, CSRF and consent are not
     * part of the cache (HC-9).
     */
    public function render(HomeContent $content, CompanySettings $company, StructuredData $structuredData, FaqContent $faqContent): View
    {
        $locale = app()->getLocale();
        $build = fn (): array => $this->payload($content, $company, $structuredData, $faqContent);

        try {
            $viewData = $this->hydrate(HomeCache::remember($locale, $build), $structuredData);
        } catch (Throwable $exception) {
            // A cached payload of an older shape: drop it and rebuild from the database.
            report($exception);
            HomeCache::forget($locale);
            $viewData = $this->hydrate(HomeCache::remember($locale, $build), $structuredData);
        }

        return view('livewire.site.home', $viewData)
            ->title(__('site.seo.home.title'))
            ->layoutData(['description' => __('site.seo.home.description')]);
    }

    /**
     * View data from a payload of plain arrays.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function hydrate(array $data, StructuredData $structuredData): array
    {
        return [
            'heroStats' => $data['hero_stats'],
            'banners' => array_map(BannerSlide::fromArray(...), $data['banners']),
            'categories' => array_map(CategoryTile::fromArray(...), $data['categories']),
            'products' => array_map(ProductCard::fromArray(...), $data['products']),
            'services' => array_map(ServiceItem::fromArray(...), $data['services']),
            'brands' => array_map(BrandItem::fromArray(...), $data['brands']),
            'news' => array_map(NewsCard::fromArray(...), $data['news']),
            'socialLinks' => $data['social'],
            'organization' => StructuredData::toScript($data['organization']),
            'localBusiness' => StructuredData::toScript($data['local_business']),
            'faq' => $data['faq'],
            'faqJson' => $data['faq'] !== [] ? StructuredData::toScript($structuredData->faqPage($data['faq'])) : null,
        ];
    }

    /**
     * Everything the page shows from the database, as plain arrays in the current locale.
     *
     * @return array<string, mixed>
     */
    protected function payload(HomeContent $content, CompanySettings $company, StructuredData $structuredData, FaqContent $faqContent): array
    {
        return [
            'hero_stats' => $company->heroStats(),
            'banners' => array_map(fn (BannerSlide $slide): array => $slide->toArray(), BannerDirectory::home()),
            'categories' => array_map(fn (CategoryTile $tile): array => $tile->toArray(), $content->categories()),
            'products' => array_map(fn (ProductCard $card): array => $card->toArray(), $content->popularProducts()),
            'services' => array_map(fn (ServiceItem $item): array => $item->toArray(), $content->services()),
            'brands' => array_map(fn (BrandItem $item): array => $item->toArray(), $content->brands()),
            'news' => array_map(fn (NewsCard $card): array => $card->toArray(), NewsCards::latest()),
            'social' => $company->social(),
            'organization' => $structuredData->organization(),
            'local_business' => $structuredData->localBusiness(),
            'faq' => $faqContent->general(),
        ];
    }
}
