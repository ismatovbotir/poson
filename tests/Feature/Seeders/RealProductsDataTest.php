<?php

use App\Actions\Catalog\SaveProduct;
use App\Models\Brand;
use App\Rules\BrandSafeText;
use App\Support\DemoContent;
use App\Support\HtmlSanitizer;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\ProductsSeeder;

/**
 * Plan 16 data checks over every existing database/seeders/data/products/*.php
 * row. Passes vacuously while no file exists.
 *
 * @return list<array{0: string, 1: array<string, mixed>}>
 */
function realProductRows(): array
{
    $files = ProductsSeeder::dataFiles();
    $rows = [];

    foreach ($files as $file) {
        foreach (ProductsSeeder::requireFile($file) as $row) {
            $rows[] = [basename(dirname($file)).'/'.basename($file), $row];
        }
    }

    return $rows;
}

function realProductTexts(array $row): array
{
    $texts = [];

    foreach (ProductsSeeder::TEXT_FIELDS as $field) {
        foreach ((array) ($row[$field] ?? []) as $locale => $text) {
            $texts["{$field}.{$locale}"] = (string) $text;
        }
    }

    foreach (['model', 'brand'] as $field) {
        if (is_string($row[$field] ?? null)) {
            $texts[$field] = $row[$field];
        }
    }

    foreach ((array) ($row['specs'] ?? []) as $code => $spec) {
        $value = is_array($spec) && array_key_exists('value', $spec) ? $spec['value'] : $spec;

        foreach ((array) $value as $index => $text) {
            if (is_string($text)) {
                $texts["specs.{$code}.{$index}"] = $text;
            }
        }
    }

    return $texts;
}

it('keeps every real product row within the whitelist, with no cost, dealer or wholesale data (S5)', function () {
    foreach (realProductRows() as [$file, $row]) {
        expect(ProductsSeeder::problems($row))->toBe([], "{$file} {$row['sku']}");

        // price_dealer / price_wholesale may only appear as null (ProductsSeeder::problems()).
        foreach (array_diff(array_keys($row), ProductsSeeder::NULL_ONLY_KEYS) as $key) {
            expect($key)->not->toMatch('/cost|purchase|zakup|^opt|dealer|wholesale|margin|markup/i', "{$file} {$row['sku']}");
        }
    }

    expect(true)->toBeTrue();
});

it('gives every row a valid, unique 1C SKU that is no demo SKU (S6)', function () {
    $seen = [];

    foreach (realProductRows() as [$file, $row]) {
        $sku = mb_strtoupper(trim($row['sku']));

        expect($sku)->toBe($row['sku'], "{$file}: SKU must be stored exactly as upper-case 1C code")
            ->and(preg_match(SaveProduct::SKU_PATTERN, $sku))->toBe(1, "{$file} {$sku}")
            ->and(mb_strlen($sku))->toBeLessThanOrEqual(64)
            ->and(DemoContent::productSkus())->not->toContain($sku);

        $this->assertArrayNotHasKey($sku, $seen, "duplicate {$sku}");
        $seen[$sku] = true;
    }

    expect(true)->toBeTrue();
});

it('keeps plain-text fields plain and descriptions a sanitizer fixed point (S9)', function () {
    foreach (realProductRows() as [$file, $row]) {
        foreach (['name', 'short_description', 'meta_title', 'meta_description'] as $field) {
            foreach ((array) ($row[$field] ?? []) as $locale => $text) {
                expect($text)->not->toMatch('/[<>]|[\x00-\x08\x0B\x0C\x0E-\x1F]/u', "{$file} {$row['sku']} {$field}.{$locale}");
            }
        }

        foreach ((array) ($row['description'] ?? []) as $locale => $html) {
            expect(HtmlSanitizer::clean($html))->toBe($html, "{$file} {$row['sku']} description.{$locale}");
        }
    }

    expect(true)->toBeTrue();
});

it('breaks no brand rule in any field or locale (S10)', function () {
    foreach (realProductRows() as [$file, $row]) {
        foreach (realProductTexts($row) as $path => $text) {
            expect(BrandSafeText::violates(strip_tags($text)))->toBeFalse("{$file} {$row['sku']} {$path}");
        }
    }

    expect(true)->toBeTrue();
});

it('carries no URL, e-mail, phone or prompt-injection text (S11)', function () {
    foreach (realProductRows() as [$file, $row]) {
        foreach (realProductTexts($row) as $path => $text) {
            expect($text)->not->toMatch('~https?:|www\.|@|\+?\d[\d\s().-]{8,}\d|data:|javascript:~i', "{$file} {$row['sku']} {$path}")
                ->and(mb_strtolower($text))->not->toMatch('/ignore (all |previous|the above)|assistant|system prompt|<script|onerror=/', "{$file} {$row['sku']} {$path}");
        }
    }

    expect(true)->toBeTrue();
});

it('uses only seeded categories, brands, attribute codes and option values', function () {
    $this->seed(CatalogSeeder::class);

    $categories = collect(require database_path('seeders/data/categories.php'))
        ->flatMap(fn (array $node) => [$node, ...$node['children']])->pluck('slug')->all();
    $brands = Brand::query()->pluck('name')->map(fn (string $name) => mb_strtolower($name))->all();
    $attributes = collect(require database_path('seeders/data/attributes.php'))->keyBy('code');

    foreach (realProductRows() as [$file, $row]) {
        $this->assertContains($row['category'], $categories, "{$file} {$row['sku']} category");

        if (filled($row['brand'] ?? null)) {
            $this->assertContains(mb_strtolower($row['brand']), $brands, "{$file} {$row['sku']} brand");
        }

        foreach (array_keys((array) ($row['specs'] ?? [])) as $code) {
            expect($attributes->has($code))->toBeTrue("{$file} {$row['sku']} spec {$code}");
        }
    }
});

it('keeps brand names plain: brand rule, no URL or domain (S17)', function () {
    $names = [...CatalogSeeder::BRANDS];

    foreach (realProductRows() as [, $row]) {
        if (filled($row['brand'] ?? null)) {
            $names[] = $row['brand'];
        }
    }

    foreach (array_unique($names) as $name) {
        expect(BrandSafeText::violates($name))->toBeFalse($name)
            ->and(preg_match('~https?:|www\.|\.[a-z]{2,}$~i', $name))->toBe(0, $name);
    }
});

it('has no fractional per-label price and no price on suspicious rows (plan 16)', function () {
    foreach (realProductRows() as [$file, $row]) {
        if (($row['pricing_unit'] ?? 'unit') === 'label' && $row['price'] !== null) {
            expect(ProductsSeeder::isWholeSom($row['price']))->toBeTrue("{$file} {$row['sku']}");
        }

        if ($row['suspicious_price'] ?? false) {
            expect($row['price'])->toBeNull("{$file} {$row['sku']}");
        }

        expect(array_key_exists('source_url', $row))->toBeFalse("{$file} {$row['sku']}");
    }
});
