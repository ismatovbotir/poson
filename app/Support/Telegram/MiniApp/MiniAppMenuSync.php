<?php

namespace App\Support\Telegram\MiniApp;

use App\Enums\SettingsArea;
use App\Jobs\SyncMiniAppMenus;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * The menu button fan-out of the Mini App switch (plan 20 M6, MA-35..37),
 * kept in the settings store (no schema change):
 *
 *  - "telegram.mini_app_menu_generation" (int): bumped under a row lock by
 *    every toggle that changes who sees the Mini App (SetMiniAppEnabled,
 *    SetBotEnabled while the Mini App row is on) and by
 *    `telegram:mini-app-menu --all`. A fan-out whose generation is no longer
 *    current stops at its next step (MA-37);
 *  - "telegram.mini_app_menu_sync" (array): display state for the admin page
 *    (generation, state, queued_at, last_run_at, finished_at, synced, cursor). Only
 *    counts and times, never a Telegram id or an API response.
 *
 * start() queues SyncMiniAppMenus only after the surrounding transaction
 * commits (DB::afterCommit, so a rollback leaves no job and no unique lock).
 */
final class MiniAppMenuSync
{
    public const GENERATION_KEY = 'telegram.mini_app_menu_generation';

    public const STATE_KEY = 'telegram.mini_app_menu_sync';

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const STATES = [self::QUEUED, self::RUNNING, self::DONE, self::FAILED];

    /**
     * Bump the generation and queue a new fan-out once the transaction commits.
     *
     * @return int the new generation
     */
    public static function start(): int
    {
        if (! Setting::query()->where('key', self::GENERATION_KEY)->exists()) {
            Setting::query()->createOrFirst(['key' => self::GENERATION_KEY], ['group' => SettingsArea::TelegramBot->group(), 'value' => 0]);
        }

        return DB::transaction(function (): int {
            $row = Setting::query()->where('key', self::GENERATION_KEY)->lockForUpdate()->firstOrFail();
            $generation = (is_int($row->value) ? max(0, $row->value) : 0) + 1;
            $row->forceFill(['group' => SettingsArea::TelegramBot->group(), 'value' => $generation])->save();

            self::writeState([
                'generation' => $generation,
                'state' => self::QUEUED,
                'queued_at' => now()->toIso8601String(),
                'last_run_at' => null,
                'finished_at' => null,
                'synced' => 0,
                'cursor' => 0,
            ]);

            DB::afterCommit(fn () => dispatch(new SyncMiniAppMenus($generation)));

            return $generation;
        });
    }

    /**
     * The current generation, read fresh (0 = never started).
     */
    public static function current(): int
    {
        $value = Setting::query()->where('key', self::GENERATION_KEY)->where('group', SettingsArea::TelegramBot->group())->first()?->value;

        return is_int($value) ? max(0, $value) : 0;
    }

    /**
     * Display state; null before the first sync.
     *
     * @return array{generation: int, state: string, queued_at: string|null, last_run_at: string|null, finished_at: string|null, synced: int, cursor: int}|null
     */
    public static function state(): ?array
    {
        $value = Setting::query()->where('key', self::STATE_KEY)->where('group', SettingsArea::TelegramBot->group())->first()?->value;

        if (! is_array($value) || ! is_int($value['generation'] ?? null) || ! in_array($value['state'] ?? null, self::STATES, true)) {
            return null;
        }

        $time = fn (string $key): ?string => is_string($value[$key] ?? null) ? $value[$key] : null;

        return [
            'generation' => $value['generation'],
            'state' => $value['state'],
            'queued_at' => $time('queued_at'),
            'last_run_at' => $time('last_run_at'),
            'finished_at' => $time('finished_at'),
            'synced' => is_int($value['synced'] ?? null) ? max(0, $value['synced']) : 0,
            'cursor' => is_int($value['cursor'] ?? null) ? max(0, $value['cursor']) : 0,
        ];
    }

    /**
     * Record a finished step (chats queued from $afterId up to $cursor). Idempotent per
     * (generation, cursor): a retried step whose cursor is already recorded adds nothing.
     */
    public static function recordStep(int $generation, int $afterId, int $cursor, int $queued, bool $more): void
    {
        $state = self::state();

        if ($state === null || $state['generation'] !== $generation || $state['cursor'] !== $afterId || self::current() !== $generation) {
            return;
        }

        $state['state'] = $more ? self::RUNNING : self::DONE;
        $state['synced'] += $queued;
        $state['cursor'] = $cursor;
        $state['last_run_at'] = now()->toIso8601String();
        $state['finished_at'] = $more ? null : now()->toIso8601String();

        self::writeState($state);
    }

    /**
     * Record a state change of the fan-out (running / failed), only while its generation is current.
     *
     * @param  array{state?: string, finished?: bool}  $changes
     */
    public static function record(int $generation, array $changes): void
    {
        if (self::current() !== $generation) {
            return;
        }

        $state = self::state() ?? ['generation' => $generation, 'state' => self::QUEUED, 'queued_at' => null, 'last_run_at' => null, 'finished_at' => null, 'synced' => 0, 'cursor' => 0];

        // A retried first step does not turn a finished fan-out back into "running".
        if ($state['generation'] !== $generation || ($state['state'] === self::DONE && ($changes['state'] ?? null) === self::RUNNING)) {
            return;
        }

        $state['state'] = $changes['state'] ?? $state['state'];
        $state['last_run_at'] = now()->toIso8601String();
        $state['finished_at'] = ($changes['finished'] ?? false) ? now()->toIso8601String() : $state['finished_at'];

        self::writeState($state);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private static function writeState(array $state): void
    {
        Setting::query()->updateOrCreate(['key' => self::STATE_KEY], ['group' => SettingsArea::TelegramBot->group(), 'value' => $state]);
    }
}
