<?php

if (! function_exists('mcpFlatKeys')) {
    /**
     * Every array key of a nested result, lower-cased.
     *
     * @return list<string>
     */
    function mcpFlatKeys(mixed $data): array
    {
        $keys = [];

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (is_string($key)) {
                    $keys[] = strtolower($key);
                }

                array_push($keys, ...mcpFlatKeys($value));
            }
        }

        return $keys;
    }
}

if (! function_exists('mcpForbiddenKeys')) {
    /**
     * Keys that must never appear in catalog / content output (plan 13 S30).
     *
     * @return list<string>
     */
    function mcpForbiddenKeys(): array
    {
        return ['price_dealer', 'price_wholesale', 'price_level', 'ikpu_code', 'package_code', 'price', 'badge', 'author', 'author_id', 'email',
            'phone', 'token', 'path', 'url', 'old_price', 'compare_at', 'discount', 'image', 'images', 'documents', 'search_text'];
    }
}
