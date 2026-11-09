<?php

namespace App\Support\Chat;

use App\Enums\SettingsArea;
use App\Models\Page;
use App\Models\Setting;
use App\Support\Telegram\Bot\BotChecklist;

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
     *     products_shown: int
     * }
     */
    public static function all(): array
    {
        $rows = Setting::query()
            ->where('group', SettingsArea::Chatbot->group())
            ->where('key', 'like', 'chatbot.%')
            ->pluck('value', 'key')
            ->all();

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
        ];
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
    public static function privacyReady(): bool
    {
        return Page::query()
            ->where('slug', BotChecklist::PRIVACY_SLUG)
            ->where('is_active', true)
            ->where('needs_review', false)
            ->exists();
    }
}
