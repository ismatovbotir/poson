<?php

namespace App\Support\Ai;

use App\Models\AiPromptOverride;
use Illuminate\Support\Facades\Cache;

/**
 * Admin overrides of the default AI hints (plan 12), field key => text,
 * cached forever and flushed by AiPromptOverride on save / delete. Not
 * memoized in the process (queue workers would keep a stale copy); a
 * cache read per lookup is cheap. Rows of keys no longer registered in
 * FieldRegistry and blank texts are ignored.
 */
final class PromptOverrides
{
    public const CACHE_KEY = 'ai.prompt-overrides';

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        $overrides = Cache::rememberForever(self::CACHE_KEY, fn (): array => AiPromptOverride::query()
            ->orderBy('field_key')
            ->pluck('prompt', 'field_key')
            ->map(fn (mixed $prompt): string => (string) $prompt)
            ->all());

        return array_filter(
            $overrides,
            fn (string $prompt, string $key): bool => trim($prompt) !== '' && FieldRegistry::has($key),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * The override of a registered field, or null (default hint applies).
     */
    public static function for(string $fieldKey): ?string
    {
        return self::all()[$fieldKey] ?? null;
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
