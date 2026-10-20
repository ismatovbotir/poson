<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * @return array<string, string>
 */
function langFlat(string $locale, string $file): array
{
    $path = lang_path("{$locale}/{$file}.php");

    if (! is_file($path)) {
        return [];
    }

    return Arr::dot(require $path);
}

/**
 * @return list<string>
 */
function langPlaceholders(string $value): array
{
    preg_match_all('/:([A-Za-z_]+)/', $value, $matches);
    $tokens = array_unique(array_map(strtolower(...), $matches[1]));
    sort($tokens);

    return array_values($tokens);
}

dataset('lang files', collect(glob(dirname(__DIR__, 3).'/lang/en/*.php'))
    ->map(fn (string $path) => basename($path, '.php'))
    ->values()
    ->all());

it('has the same keys in uz and ru as in en', function (string $file) {
    $enKeys = array_keys(langFlat('en', $file));
    $isOptional = fn (string $key) => $file === 'validation' && Str::startsWith($key, 'custom.');

    foreach (['uz', 'ru'] as $locale) {
        $keys = array_keys(langFlat($locale, $file));
        $missing = array_values(array_filter(array_diff($enKeys, $keys), fn ($key) => ! $isOptional($key)));
        $extra = array_values(array_filter(array_diff($keys, $enKeys), fn ($key) => ! $isOptional($key)));

        expect($missing)->toBe([], "{$locale}/{$file}.php is missing keys: ".implode(', ', $missing))
            ->and($extra)->toBe([], "{$locale}/{$file}.php has extra keys: ".implode(', ', $extra));
    }
})->with('lang files');

it('uses the same placeholders in every locale', function (string $file) {
    $locales = ['en' => langFlat('en', $file), 'uz' => langFlat('uz', $file), 'ru' => langFlat('ru', $file)];
    $mismatches = [];

    foreach ($locales['en'] as $key => $enValue) {
        if (! is_string($enValue)) {
            continue;
        }

        $expected = langPlaceholders($enValue);

        foreach (['uz', 'ru'] as $locale) {
            $value = $locales[$locale][$key] ?? null;

            if (! is_string($value) || str_contains($value, '|') || str_contains($enValue, '|')) {
                continue;
            }

            $actual = langPlaceholders($value);

            if ($actual !== $expected) {
                $mismatches[] = "{$locale}.{$file}.{$key}: expected [".implode(',', $expected).'] got ['.implode(',', $actual).']';
            }
        }
    }

    expect($mismatches)->toBe([], "Placeholder mismatches:\n".implode("\n", $mismatches));
})->with('lang files');

it('writes uz in Latin script with the ʻ okina', function (string $file) {
    $problems = [];

    foreach (langFlat('uz', $file) as $key => $value) {
        if (! is_string($value)) {
            continue;
        }

        if (preg_match('/\p{Cyrillic}/u', $value) && "{$file}.{$key}" !== 'site.locale.ru') {
            $problems[] = "uz.{$file}.{$key} contains Cyrillic: {$value}";
        }

        if (preg_match("/\\p{Latin}['\u{2018}\u{2019}]\\p{Latin}/u", $value)) {
            $problems[] = "uz.{$file}.{$key} uses a wrong apostrophe (use ʻ U+02BB): {$value}";
        }
    }

    expect($problems)->toBe([], "uz script problems:\n".implode("\n", $problems));
})->with('lang files');
