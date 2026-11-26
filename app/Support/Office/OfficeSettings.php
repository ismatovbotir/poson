<?php

namespace App\Support\Office;

use App\Enums\AiUsageChannel;
use App\Enums\SettingsArea;
use App\Models\Setting;
use App\Support\Chat\AiSwitch;
use ArrayObject;
use Throwable;

/**
 * Admin settings of the Virtual office (plan 41 §8.5): rows of the settings
 * table in group "office", keys "office.*", written only by
 * SaveOfficeSettings (Admin, audited). Every getter falls back to a safe
 * default: ingest and AI are OFF, the owner allow-list is EMPTY (VO-1).
 *
 * The owner allow-list: a non-empty config office.owner_telegram_ids
 * (OFFICE_OWNER_TELEGRAM_IDS) overrides and locks the stored value.
 *
 * Memoized for the request or queued job (scoped container instance) and
 * dropped when an office row is saved (Setting::flushCaches).
 */
final class OfficeSettings
{
    public const GROUP = 'office';

    public const INGEST = 'office.ingest_enabled';

    public const GROUPS = 'office.groups_enabled';

    public const AI = 'office.ai_enabled';

    public const AUTO_LEAD = 'office.auto_lead';

    public const OWNERS = 'office.owner_telegram_ids';

    public const DEBOUNCE = 'office.debounce_minutes';

    public const PER_CHAT = 'office.per_chat_daily';

    public const CAPS = 'office.caps';

    public const KEYS = [self::INGEST, self::GROUPS, self::AI, self::AUTO_LEAD, self::OWNERS, self::DEBOUNCE, self::PER_CHAT, self::CAPS];

    private const STATE = 'office.settings.state';

    public static function register(): void
    {
        app()->scoped(self::STATE, fn () => new ArrayObject);
    }

    public static function forget(): void
    {
        if (app()->bound(self::STATE)) {
            app(self::STATE)->exchangeArray([]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function rows(): array
    {
        if (! app()->bound(self::STATE)) {
            self::register();
        }

        $state = app(self::STATE);

        if (isset($state['rows'])) {
            return $state['rows'];
        }

        try {
            $rows = Setting::query()->where('group', SettingsArea::Office->group())->whereIn('key', self::KEYS)->pluck('value', 'key')->all();
        } catch (Throwable $exception) {
            report($exception);

            // A failed read is never memoized: everything is off for this call only.
            return [];
        }

        return $state['rows'] = $rows;
    }

    private static function get(string $key, mixed $default = null): mixed
    {
        return self::rows()[$key] ?? $default;
    }

    public static function ingestEnabled(): bool
    {
        return self::get(self::INGEST) === true;
    }

    /**
     * Telegram group ingest (plan 44 §2): its own Admin switch, default off, independent of BotSwitch.
     * Group updates are processed only while this AND the office ingest switch are on.
     */
    public static function groupsStored(): bool
    {
        return self::get(self::GROUPS) === true;
    }

    public static function groupsEnabled(): bool
    {
        return self::groupsStored() && self::ingestEnabled();
    }

    /**
     * The raw AI switch an Admin saved.
     */
    public static function aiStored(): bool
    {
        return self::get(self::AI) === true;
    }

    /**
     * Analyses may call the model: switch on, API key in .env, not paused by a 401/403.
     */
    public static function aiEnabled(): bool
    {
        return self::aiStored() && AiSwitch::keyConfigured() && ! AiSwitch::authFailed();
    }

    /**
     * Leads are created automatically for assigned sales / service chats (default on, owner answer §16.4).
     */
    public static function autoLead(): bool
    {
        return self::get(self::AUTO_LEAD, true) !== false;
    }

    /**
     * The owner allow-list (VO-1): the locked config value, else the stored one.
     *
     * @return list<int>
     */
    public static function ownerIds(): array
    {
        $locked = self::lockedOwnerIds();

        if ($locked !== null) {
            return $locked;
        }

        return self::parseIds(self::get(self::OWNERS, []));
    }

    /**
     * The ids from OFFICE_OWNER_TELEGRAM_IDS, or null when it is empty (the admin value applies).
     *
     * @return list<int>|null
     */
    public static function lockedOwnerIds(): ?array
    {
        $ids = self::parseIds(config('office.owner_telegram_ids'));

        return $ids === [] ? null : $ids;
    }

    public static function ownerIdsLocked(): bool
    {
        return self::lockedOwnerIds() !== null;
    }

    public static function isOwner(int $telegramId): bool
    {
        return in_array($telegramId, self::ownerIds(), true);
    }

    /**
     * Positive integers from a list or a comma / space separated string, unique, at most 10.
     *
     * @return list<int>
     */
    public static function parseIds(mixed $value): array
    {
        $parts = is_array($value) ? $value : preg_split('/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
        $ids = [];

        foreach ((array) $parts as $part) {
            $id = filter_var($part, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($id !== false) {
                $ids[$id] = $id;
            }
        }

        return array_slice(array_values($ids), 0, 10);
    }

    public static function debounceMinutes(): int
    {
        $value = filter_var(self::get(self::DEBOUNCE), FILTER_VALIDATE_INT);
        $min = (int) config('office.debounce_min', 5);
        $max = (int) config('office.debounce_max', 60);

        return $value !== false && $value >= $min && $value <= $max ? $value : (int) config('office.debounce_minutes', 10);
    }

    public static function perChatDaily(): int
    {
        $value = filter_var(self::get(self::PER_CHAT), FILTER_VALIDATE_INT);

        return $value !== false && $value >= 1 && $value <= (int) config('office.per_chat_daily_max', 24) ? $value : (int) config('office.per_chat_daily', 12);
    }

    /**
     * The default daily caps of an office channel (config).
     *
     * @return array{requests: int, tokens: int}
     */
    public static function defaultCaps(AiUsageChannel $channel): array
    {
        $config = (array) config('office.caps.'.$channel->value, []);

        return ['requests' => max(0, (int) ($config['requests'] ?? 0)), 'tokens' => max(0, (int) ($config['tokens'] ?? 0))];
    }

    /**
     * The highest caps an Admin may set (cap_factor × default, VO-11).
     *
     * @return array{requests: int, tokens: int}
     */
    public static function maxCaps(AiUsageChannel $channel): array
    {
        $default = self::defaultCaps($channel);
        $factor = max(1, (int) config('office.cap_factor', 5));

        return ['requests' => $default['requests'] * $factor, 'tokens' => $default['tokens'] * $factor];
    }

    /**
     * Today's caps of an office channel: the stored value within bounds, else the default.
     *
     * @return array{requests: int, tokens: int}
     */
    public static function caps(AiUsageChannel $channel): array
    {
        $default = self::defaultCaps($channel);
        $max = self::maxCaps($channel);
        $stored = self::get(self::CAPS, []);
        $row = is_array($stored) && is_array($stored[$channel->value] ?? null) ? $stored[$channel->value] : [];
        $caps = [];

        foreach (['requests', 'tokens'] as $cap) {
            $value = filter_var($row[$cap] ?? null, FILTER_VALIDATE_INT);
            $caps[$cap] = $value !== false && $value >= 0 && $value <= $max[$cap] ? $value : $default[$cap];
        }

        return $caps;
    }
}
