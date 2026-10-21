<?php

namespace App\Support\Site;

use Illuminate\Support\Str;

/**
 * Title and meta description fallbacks of catalog pages (plan 03 T19):
 * meta_title, else "{name} — Poson"; meta_description, else the given text
 * (short description, description) as plain text cut to about 155 characters.
 */
class SeoText
{
    public const DESCRIPTION_LENGTH = 155;

    public static function title(?string $metaTitle, string $name): string
    {
        $metaTitle = trim((string) $metaTitle);

        return $metaTitle !== '' ? $metaTitle : $name.' — '.__('site.meta.site_name');
    }

    /**
     * The first non-empty candidate, plain text, trimmed at a word boundary.
     */
    public static function description(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $text = StructuredData::plainText((string) $candidate);

            if ($text !== '') {
                return Str::limit($text, self::DESCRIPTION_LENGTH, '…', preserveWords: true);
            }
        }

        return null;
    }
}
