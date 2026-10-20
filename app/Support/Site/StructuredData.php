<?php

namespace App\Support\Site;

use App\Support\CompanySettings;

/**
 * schema.org JSON-LD for public pages.
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
