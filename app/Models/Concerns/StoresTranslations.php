<?php

namespace App\Models\Concerns;

/**
 * Write a whole translatable json column at once (used with spatie HasTranslations).
 *
 * Values are trimmed and empty locales dropped, so clearing the ru text
 * removes it (and the site falls back to uz). A column with nothing left
 * is stored as NULL rather than "[]".
 */
trait StoresTranslations
{
    /**
     * @param  array<string, string|null>  $values  locale => text
     */
    public function storeTranslations(string $key, array $values): static
    {
        $clean = [];

        foreach (config('app.supported_locales') as $locale) {
            $value = trim((string) ($values[$locale] ?? ''));

            if ($value !== '') {
                $clean[$locale] = $value;
            }
        }

        $this->attributes[$key] = $clean === []
            ? null
            : json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $this;
    }
}
