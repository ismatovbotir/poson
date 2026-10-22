<?php

namespace App\Support\Site;

use App\Enums\StockStatus;
use App\Models\Product;
use App\Support\Catalog\CategoryTree;
use App\Support\CompanySettings;
use App\Support\Settings\SettingsFormat;
use Illuminate\Support\Str;

/**
 * schema.org JSON-LD for public pages: Organization and LocalBusiness (home),
 * BreadcrumbList, Product with its Offer, and FAQPage (plan 04 T12; only
 * where the FAQ is visible on the page). Every block is printed with
 * toScript().
 *
 * Brand rule: the Offer carries the Retail price only; Dealer and Wholesale
 * prices never appear in structured data.
 */
class StructuredData
{
    public function __construct(protected CompanySettings $company) {}

    /**
     * Organization for the home page (plan 04): name, url, logo, contacts per
     * phone label, address, sameAs (social links), legalName and taxID (INN)
     * from the company settings. Empty values are left out.
     *
     * @return array<string, mixed>
     */
    public function organization(): array
    {
        $legal = $this->company->legal();
        $url = $this->homeUrl();

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            '@id' => $url.'#organization',
            'name' => $this->company->name(),
            'url' => $url,
            'logo' => $this->company->logoUrl() ?: asset('assets/site/logo.svg'),
            'description' => __('site.meta.description'),
        ];

        $optional = [
            'legalName' => $legal['legal_name'],
            'taxID' => $legal['inn'],
            'vatID' => $legal['vat_code'],
            'telephone' => $this->company->phone(),
            'email' => $this->company->email(),
        ];

        foreach ($optional as $property => $value) {
            if ($value !== '') {
                $data[$property] = $value;
            }
        }

        $contactPoints = $this->contactPoints();

        if ($contactPoints !== []) {
            $data['contactPoint'] = $contactPoints;
        }

        $data['address'] = $this->postalAddress();

        $sameAs = array_values(array_unique(array_column($this->company->social(), 'url')));

        if ($sameAs !== []) {
            $data['sameAs'] = $sameAs;
        }

        return $data;
    }

    /**
     * LocalBusiness for the home page (plan 04): the office with address,
     * coordinates and opening hours. No priceRange (brand rule).
     *
     * @return array<string, mixed>
     */
    public function localBusiness(): array
    {
        $url = $this->homeUrl();

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            '@id' => $url.'#localbusiness',
            'name' => $this->company->name(),
            'url' => $url,
            'image' => $this->company->ogImageUrl() ?: ($this->company->logoUrl() ?: asset('assets/site/logo.svg')),
            'parentOrganization' => ['@id' => $url.'#organization'],
            'address' => $this->postalAddress(),
        ];

        if ($this->company->phone() !== '') {
            $data['telephone'] = $this->company->phone();
        }

        if ($this->company->email() !== '') {
            $data['email'] = $this->company->email();
        }

        $geo = $this->company->geo();

        if ($geo !== null) {
            $data['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => $geo['lat'], 'longitude' => $geo['lng']];
        }

        if ($this->company->mapUrl() !== '') {
            $data['hasMap'] = $this->company->mapUrl();
        }

        $hours = [];

        foreach ($this->company->openingHours() as $row) {
            $hours[] = [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => array_map(fn (string $day): string => 'https://schema.org/'.SettingsFormat::DAYS[$day], $row['days']),
                'opens' => $row['opens'],
                'closes' => $row['closes'],
            ];
        }

        if ($hours !== []) {
            $data['openingHoursSpecification'] = $hours;
        }

        return $data;
    }

    /**
     * One ContactPoint per phone label (the first site number of each label;
     * numbers not marked for the site are never published).
     *
     * @return list<array<string, mixed>>
     */
    protected function contactPoints(): array
    {
        $points = [];

        foreach ($this->company->sitePhones() as $phone) {
            $type = $phone['label']->contactType();

            if (isset($points[$type])) {
                continue;
            }

            $points[$type] = [
                '@type' => 'ContactPoint',
                'telephone' => $phone['number'],
                'contactType' => $type,
                'areaServed' => 'UZ',
                'availableLanguage' => ['uz', 'ru', 'en'],
            ];
        }

        return array_values($points);
    }

    /**
     * @return array<string, string>
     */
    protected function postalAddress(): array
    {
        return array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => $this->company->address(),
            'addressLocality' => $this->company->city(),
            'addressCountry' => 'UZ',
        ], fn (string $value): bool => $value !== '');
    }

    protected function homeUrl(): string
    {
        return route('site.home', ['locale' => app()->getLocale()]);
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
     * FAQPage for the questions shown on the page (plain text answers).
     * Call only with a non-empty list: no FAQ on the page, no FAQPage.
     *
     * @param  list<array{question: string, answer: string}>  $items
     * @return array<string, mixed>
     */
    public function faqPage(array $items): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn (array $item): array => [
                '@type' => 'Question',
                'name' => $item['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $item['answer'],
                ],
            ], array_values($items)),
        ];
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
