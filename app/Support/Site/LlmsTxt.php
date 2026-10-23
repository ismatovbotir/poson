<?php

namespace App\Support\Site;

use App\Actions\Settings\UpdateCompanySettings;
use App\Enums\PageTemplate;
use App\Rules\BrandSafeText;
use App\Support\Catalog\CategoryTree;
use App\Support\CompanySettings;
use Illuminate\Support\Facades\Cache;

/**
 * /llms.txt (plan 04 §8, T12; llmstxt.org convention): "# Poson", an English
 * "> summary", then About, Services, Catalog (visible top-level categories
 * and the main services category, absolute English URLs), Pages (active
 * static pages except legal ones, plan 07) and Contacts, plus
 * short Russian and Uzbek sections linking to /ru and /uz.
 *
 * Text comes from config/llms.php, contacts from CompanySettings: email,
 * phone and address only when valid AND entered in admin with a value other
 * than the config/company.php default (those are placeholders until the
 * client fills them in). Never any price.
 *
 * Built on APP_URL (CanonicalHost) and cached for CACHE_TTL seconds; flushed
 * by Category, Product and Page saves/deletes, category reordering and
 * CompanySettings::flush().
 */
class LlmsTxt
{
    public const CACHE_KEY = 'site.llms-txt';

    public const CACHE_TTL = 3600;

    public function __construct(protected CompanySettings $company) {}

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function text(): string
    {
        return Cache::remember(
            self::CACHE_KEY,
            now()->addSeconds(self::CACHE_TTL),
            fn (): string => CanonicalHost::using(fn (): string => $this->build()),
        );
    }

    public function build(): string
    {
        $config = (array) config('llms');
        $labels = (array) ($config['labels'] ?? []);

        $blocks = [
            '# '.$this->line($config['title'] ?? 'Poson'),
            '> '.$this->line($config['summary']['en'] ?? ''),
        ];

        if (filled($config['contact_note']['en'] ?? null)) {
            $blocks[] = $this->line($config['contact_note']['en']);
        }

        $blocks[] = $this->section($labels['about'] ?? 'About', $this->bullets($config['about_points']['en'] ?? []));
        $blocks[] = $this->section($labels['services'] ?? 'Services', $this->bullets($config['services_points']['en'] ?? []));
        $blocks[] = $this->section($labels['catalog'] ?? 'Catalog', $this->catalog((string) ($config['services_category'] ?? '')));
        $blocks[] = $this->section($labels['pages'] ?? 'Pages', $this->pages());
        $blocks[] = $this->section($labels['contacts'] ?? 'Contacts', $this->contacts($labels));

        foreach ((array) ($config['locale_sections'] ?? []) as $locale => $section) {
            if (! in_array($locale, config('app.supported_locales'), true)) {
                continue;
            }

            $blocks[] = $this->section($section['title'] ?? strtoupper($locale), [
                '> '.$this->line($config['summary'][$locale] ?? ''),
                '',
                '- '.$this->link($section['site'] ?? $locale, route('site.home', ['locale' => $locale])),
                '- '.$this->link($section['catalog'] ?? $locale, route('site.catalog.index', ['locale' => $locale])),
            ]);
        }

        return implode("\n\n", array_filter($blocks, fn (string $block): bool => $block !== ''))."\n";
    }

    /**
     * Visible top-level categories in admin order, plus the services category.
     *
     * @return list<string>
     */
    protected function catalog(string $servicesSlug): array
    {
        $nodes = CategoryTree::visibleChildren(null);
        $ids = array_column($nodes, 'id');

        foreach (CategoryTree::nodes() as $node) {
            if ($servicesSlug !== '' && $node['slug'] === $servicesSlug && ! in_array($node['id'], $ids, true) && CategoryTree::isVisible($node['id'])) {
                $nodes[] = $node;
            }
        }

        $lines = [];

        foreach ($nodes as $node) {
            $name = CategoryTree::name($node['id'], 'en');

            if ($name === '' || BrandSafeText::violates($name)) {
                continue;
            }

            $lines[] = '- '.$this->link($name, route('site.catalog.category', ['locale' => 'en', 'category' => $node['slug']]));
        }

        return $lines;
    }

    /**
     * Active static pages (PageDirectory) with English titles and URLs;
     * legal pages (privacy, offer) are left out (plan 07).
     *
     * @return list<string>
     */
    protected function pages(): array
    {
        $lines = [];

        foreach (PageDirectory::active() as $page) {
            if ($page['template'] === PageTemplate::Legal->value) {
                continue;
            }

            $title = PageDirectory::title($page['slug'], 'en');

            if ($title === '' || BrandSafeText::violates($title)) {
                continue;
            }

            $lines[] = '- '.$this->link($title, route('site.page', ['locale' => 'en', 'page' => $page['slug']]));
        }

        return $lines;
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<string>
     */
    protected function contacts(array $labels): array
    {
        $lines = ['- '.($labels['website'] ?? 'Website').': '.route('site.home', ['locale' => 'en'])];

        if ($this->isEntered('email') && $this->company->email() !== '') {
            $lines[] = '- '.($labels['email'] ?? 'Email').': '.$this->company->email();
        }

        if ($this->isEntered('phones') && $this->company->phoneDisplay() !== '') {
            $lines[] = '- '.($labels['phone'] ?? 'Phone').': '.$this->company->phoneDisplay();
        }

        $address = $this->isEntered('address') ? $this->company->address('en') : '';

        if ($address !== '') {
            $city = $this->company->city('en');

            if ($city !== '' && mb_stripos($address, $city) === false) {
                $address .= ', '.$city;
            }

            $lines[] = '- '.($labels['address'] ?? 'Address').': '.$this->line($address);
        }

        return $lines;
    }

    /**
     * Saved in admin and different from the config placeholder.
     */
    protected function isEntered(string $key): bool
    {
        return $this->company->isCustomized($key)
            && UpdateCompanySettings::canonical($this->company->get($key)) !== UpdateCompanySettings::canonical(config("company.{$key}"));
    }

    /**
     * @param  list<string>  $lines
     */
    protected function section(string $title, array $lines): string
    {
        if ($lines === []) {
            return '';
        }

        return '## '.$this->line($title)."\n\n".implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    protected function bullets(mixed $points): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $point): string => is_string($point) && $this->line($point) !== '' ? '- '.$this->line($point) : '',
            (array) $points,
        )));
    }

    protected function link(string $label, string $url): string
    {
        return '['.str_replace(['[', ']'], ['\[', '\]'], $this->line($label)).']('.$url.')';
    }

    /**
     * One line of text: control characters and runs of whitespace collapsed.
     */
    protected function line(mixed $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', is_string($text) ? $text : ''));
    }
}
