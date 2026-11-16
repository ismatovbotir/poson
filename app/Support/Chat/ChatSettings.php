<?php

namespace App\Support\Chat;

use App\Enums\AiUsageChannel;
use App\Enums\SettingsArea;
use App\Models\Page;
use App\Models\Setting;
use App\Support\Telegram\Bot\BotChecklist;
use ArrayObject;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Admin-editable settings of the chat bot (plan 28 §5): rows of the settings
 * table in group "chatbot", keys "chatbot.*". Written only by
 * SaveChatbotSettings (which audits and applies the Admin-only switch rules);
 * read here. Every getter falls back to a safe default, so a missing row is
 * never an error. The two channel switches are read by ChatbotSwitch.
 */
final class ChatSettings
{
    public const TEXT_KEYS = ['greeting', 'fallback', 'after_hours', 'consent'];

    public const DAYS = ['mo', 'tu', 'we', 'th', 'fr', 'sa', 'su'];

    public const TARGETS = ['sales', 'service', 'any'];

    public const POSITIONS = ['right', 'left'];

    public const PAGES = ['all', 'no_cart'];

    public const TEXT_MAX = 500;

    private const CACHE_KEY = 'chatbot.settings.all';

    private const STATE = 'chatbot.settings.state';

    /**
     * Cached for an hour (flushed on save) and memoized for the rest of the request or queued job (scoped container
     * instance); dropped when a chatbot setting row is saved.
     */
    public static function register(): void
    {
        app()->scoped(self::STATE, fn () => new ArrayObject);
    }

    public static function forget(): void
    {
        app(self::STATE)->exchangeArray([]);
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Drop only the per-request/job memo (the next read sees the cache, which a save flushes):
     * a long-running job re-reads the switches without wiping the shared cache (plan 30 L3).
     */
    public static function forgetMemo(): void
    {
        app(self::STATE)->exchangeArray([]);
    }

    public static function key(string $name): string
    {
        return 'chatbot.'.$name;
    }

    /**
     * Everything the settings screen shows, with defaults filled in.
     *
     * @return array{
     *     texts: array<string, array<string, string>>,
     *     use_company_hours: bool,
     *     hours: array<string, array{open: bool, from: string, to: string}>,
     *     handoff_target: string,
     *     handoff_ttl_hours: int,
     *     widget_position: string,
     *     widget_pages: string,
     *     widget_mobile: bool,
     *     products_shown: int,
     *     ai: array{telegram_enabled: bool, web_enabled: bool, translate_enabled: bool, daily_requests: int, daily_tokens: int, instructions: string}
     * }
     */
    public static function all(): array
    {
        $state = app(self::STATE);

        if (isset($state['all'])) {
            return $state['all'];
        }

        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $state['all'] = $cached;
        }

        $failed = false;
        $loaded = self::load($failed);

        // Defaults from a failed read are never cached: the next request tries the database again.
        if (! $failed) {
            Cache::put(self::CACHE_KEY, $loaded, 3600);
        }

        return $state['all'] = $loaded;
    }

    /**
     * @return array<string, mixed>
     */
    private static function load(bool &$failed = false): array
    {
        // A broken row or a failing read never breaks a reply: every value falls back to its default.
        try {
            $rows = Setting::query()
                ->where('group', SettingsArea::Chatbot->group())
                ->where('key', 'like', 'chatbot.%')
                ->pluck('value', 'key')
                ->all();
        } catch (Throwable $e) {
            report($e);
            $failed = true;
            $rows = [];
        }

        $get = fn (string $name, mixed $default = null): mixed => $rows[self::key($name)] ?? $default;

        $texts = [];

        foreach (self::TEXT_KEYS as $name) {
            $value = is_array($get("text.{$name}")) ? $get("text.{$name}") : [];

            foreach (config('app.supported_locales') as $locale) {
                $texts[$name][$locale] = is_string($value[$locale] ?? null) ? $value[$locale] : '';
            }
        }

        $hours = [];
        $stored = is_array($get('hours')) ? $get('hours') : [];

        foreach (self::DAYS as $day) {
            $row = is_array($stored[$day] ?? null) ? $stored[$day] : [];
            $weekend = in_array($day, ['sa', 'su'], true);
            $hours[$day] = [
                'open' => (bool) ($row['open'] ?? ! $weekend),
                'from' => is_string($row['from'] ?? null) ? $row['from'] : '09:00',
                'to' => is_string($row['to'] ?? null) ? $row['to'] : '18:00',
            ];
        }

        $target = $get('handoff_target');
        $position = $get('widget_position');
        $pages = $get('widget_pages');
        $ttl = filter_var($get('handoff_ttl_hours'), FILTER_VALIDATE_INT);
        $shown = filter_var($get('products_shown'), FILTER_VALIDATE_INT);

        return [
            'texts' => $texts,
            'use_company_hours' => $get('use_company_hours', true) !== false,
            'hours' => $hours,
            'handoff_target' => in_array($target, self::TARGETS, true) ? $target : 'sales',
            'handoff_ttl_hours' => $ttl !== false && $ttl >= 1 && $ttl <= 72 ? $ttl : (int) config('chatbot.handoff_ttl_hours', 8),
            'widget_position' => in_array($position, self::POSITIONS, true) ? $position : 'right',
            'widget_pages' => in_array($pages, self::PAGES, true) ? $pages : 'all',
            'widget_mobile' => $get('widget_mobile', true) !== false,
            'products_shown' => $shown !== false && $shown >= 3 && $shown <= 8 ? $shown : 5,
            'ai' => self::ai($get),
        ];
    }

    /**
     * The AI answer settings (plan 30 §9), in the same rows and the same one
     * memoized read as everything else (CB-31: no extra query).
     *
     * Plan 32: "admin_enabled" is the product wizard switch (Admin only) and
     * "caps" the daily caps of each admin usage channel (AiUsageChannel value
     * => requests, tokens; settings override config chatbot.ai.caps.{value}).
     *
     * @param  callable(string, mixed=): mixed  $get
     * @return array{telegram_enabled: bool, web_enabled: bool, translate_enabled: bool, daily_requests: int, daily_tokens: int, instructions: string, admin_enabled: bool, caps: array<string, array{requests: int, tokens: int}>}
     */
    private static function ai(callable $get): array
    {
        $requests = filter_var($get('ai_daily_requests'), FILTER_VALIDATE_INT);
        $tokens = filter_var($get('ai_daily_tokens'), FILTER_VALIDATE_INT);
        $instructions = $get('ai_instructions');

        return [
            'telegram_enabled' => $get('ai_telegram_enabled') === true,
            'web_enabled' => $get('ai_web_enabled') === true,
            'translate_enabled' => $get('ai_translate_enabled') === true,
            'daily_requests' => $requests !== false && $requests >= 0 && $requests <= (int) config('chatbot.ai.caps.requests_max')
                ? $requests : (int) config('chatbot.ai.caps.telegram.requests'),
            'daily_tokens' => $tokens !== false && $tokens >= 0 && $tokens <= (int) config('chatbot.ai.caps.tokens_max')
                ? $tokens : (int) config('chatbot.ai.caps.telegram.tokens'),
            'instructions' => is_string($instructions) ? mb_substr($instructions, 0, (int) config('chatbot.ai.instructions_max', 2000)) : '',
            'admin_enabled' => $get('ai_admin_enabled') === true,
            'caps' => self::adminCaps($get),
        ];
    }

    /**
     * @param  callable(string, mixed=): mixed  $get
     * @return array<string, array{requests: int, tokens: int}>
     */
    private static function adminCaps(callable $get): array
    {
        $caps = [];

        foreach (AiUsageChannel::cases() as $channel) {
            if (! $channel->isAdmin()) {
                continue;
            }

            $requests = filter_var($get("ai_{$channel->value}_daily_requests"), FILTER_VALIDATE_INT);
            $tokens = filter_var($get("ai_{$channel->value}_daily_tokens"), FILTER_VALIDATE_INT);

            $caps[$channel->value] = [
                'requests' => $requests !== false && $requests >= 0 && $requests <= (int) config('chatbot.ai.caps.requests_max')
                    ? $requests : (int) config("chatbot.ai.caps.{$channel->value}.requests", 0),
                'tokens' => $tokens !== false && $tokens >= 0 && $tokens <= (int) config('chatbot.ai.caps.tokens_max')
                    ? $tokens : (int) config("chatbot.ai.caps.{$channel->value}.tokens", 0),
            ];
        }

        return $caps;
    }

    /**
     * Hours without activity before a "manager" conversation may go back to
     * the bot (CB-4, CB-31): the setting, clamped to 1-72, default 8.
     */
    public static function handoffTtlHours(): int
    {
        return max(1, min(72, (int) self::all()['handoff_ttl_hours']));
    }

    /**
     * One of the bot texts for a locale (uz when that locale is empty), or
     * null when none is set (the built-in lang text applies).
     */
    public static function text(string $name, string $locale): ?string
    {
        $text = self::all()['texts'][$name] ?? [];
        $value = trim((string) ($text[$locale] ?? '')) !== '' ? $text[$locale] : ($text['uz'] ?? '');

        return trim((string) $value) === '' ? null : $value;
    }

    /**
     * The privacy gate (CB-13, T21-24 / B6): a channel can only be switched on
     * while the privacy page is published and no longer flagged for review.
     * The same condition as the "privacy" item of the Telegram bot checklist.
     */
    public static function privacyReady(bool $fresh = false): bool
    {
        $state = app(self::STATE);

        if ($fresh) {
            unset($state['privacy']);
        }

        return $state['privacy'] ??= self::queryPrivacyReady();
    }

    private static function queryPrivacyReady(): bool
    {
        return Page::query()
            ->where('slug', BotChecklist::PRIVACY_SLUG)
            ->where('is_active', true)
            ->where('needs_review', false)
            ->exists();
    }
}
