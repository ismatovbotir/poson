<?php

namespace App\Support\Site;

use App\Enums\StockStatus;
use App\Models\Product;
use App\Support\Catalog\CategoryTree;
use App\Support\CompanySettings;
use Illuminate\Support\Str;

/**
 * schema.org JSON-LD for public pages: Organization (home), BreadcrumbList
 * and Product with its Offer. Plan 04 adds LocalBusiness and FAQ next to
 * these; every block is printed with toScript().
 *
 * Brand rule: the Offer carries the Retail price only; Dealer and Wholesale
 * prices never appear in structured data.
 */
class StructuredData
{
    public function __construct(protected CompanySettings $company) {}

    /**
     * Organization for the home page: name, url, logo and contacts from the company settings.
     *
     * @return array<string, mixed>
     */
    public function organization(): array
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => __('site.meta.site_name'),
            'url' => route('site.home', ['locale' => app()->getLocale()]),
            'logo' => asset('assets/site/logo.svg'),
            'description' => __('site.meta.description'),
        ];

        $phone = $this->company->phone();
        $email = $this->company->email();

        if ($phone !== '') {
            $data['telephone'] = $phone;
        }

        if ($email !== '') {
            $data['email'] = $email;
        }

        $address = array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => $this->company->address(),
            'addressLocality' => $this->company->city(),
            'addressCountry' => 'UZ',
        ], fn (string $value): bool => $value !== '');

        $data['address'] = $address;

        $sameAs = array_column($this->company->social(), 'url');

        if ($this->company->telegramUrl() !== '') {
            $sameAs[] = $this->company->telegramUrl();
        }

        if ($sameAs !== []) {
            $data['sameAs'] = array_values(array_unique($sameAs));
        }

        return $data;
    }

    /**
     * BreadcrumbList from the page breadcrumbs. Items without a URL (the
     * current page) get the page URL.
     *
     * @param  list<array{label: string, url: ?string}>  $items
     * @return array<string, mixed>
     */
    public function breadcrumbs(array $items, string $currentUrl): array
    {
        $elements = [];

        foreach (array_values($items) as $index => $item) {
            $elements[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $item['label'],
                'item' => $item['url'] ?? $currentUrl,
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $elements,
        ];
    }

    /**
     * Product with brand, images, description and category path; the Offer
     * (Retail price in UZS, availability, url) only when the price is known.
     * Needs "brand" and "media" loaded.
     *
     * @return array<string, mixed>
     */
    public function product(Product $product, string $url): array
    {
        $images = [];

        foreach ($product->imageMedia() as $media) {
            if ($media->hasGeneratedConversion('large')) {
                $images[] = $media->getUrl('large');
            }
        }

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => (string) $product->name,
            'sku' => $product->sku,
            'url' => $url,
            'category' => CategoryTree::path($product->category_id),
        ];

        if (filled($product->model)) {
            $data['mpn'] = $product->model;
        }

        $brand = $product->publicBrand();

        if ($brand !== null) {
            $data['brand'] = ['@type' => 'Brand', 'name' => $brand->name];
        }

        if ($images !== []) {
            $data['image'] = $images;
        }

        $description = static::plainText((string) ($product->short_description ?: $product->description));

        if ($description !== '') {
            $data['description'] = Str::limit($description, 500, '…', preserveWords: true);
        }

        if ($product->price !== null) {
            $data['offers'] = [
                '@type' => 'Offer',
                'priceCurrency' => 'UZS',
                'price' => static::decimalPrice($product->price),
                'availability' => static::availability($product->stock_status),
                'itemCondition' => 'https://schema.org/NewCondition',
                'url' => $url,
            ];
        }

        return $data;
    }

    /**
     * Tiyin → a decimal string in soʻm ("1250000.00"), no floats.
     */
    public static function decimalPrice(int $tiyin): string
    {
        return intdiv($tiyin, 100).'.'.str_pad((string) ($tiyin % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function availability(StockStatus $status): string
    {
        return match ($status) {
            StockStatus::InStock => 'https://schema.org/InStock',
            StockStatus::OnOrder => 'https://schema.org/BackOrder',
            StockStatus::OutOfStock => 'https://schema.org/OutOfStock',
        };
    }

    /**
     * Text without tags, entities decoded and whitespace collapsed.
     */
    public static function plainText(string $html): string
    {
        $text = html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES | ENT_HTML5);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Encode for a <script type="application/ld+json"> block. JSON_HEX_TAG
     * escapes "<" and ">", so values can't close the script element.
     *
     * @param  array<string, mixed>  $data
     */
    public static function toScript(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
    }
}
