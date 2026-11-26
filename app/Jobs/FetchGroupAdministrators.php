<?php

namespace App\Jobs;

use App\Actions\Office\UpsertGroupMember;
use App\Models\TelegramGroup;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Office\OfficeSettings;
use App\Support\Telegram\Bot\GroupUpdate;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * getChatAdministrators for a TRACKED group (plan 44 §2, OG-2): a read call,
 * nothing is sent to the group. Dispatch through dispatchFor(): tracked
 * groups only, at most one per group per 10 minutes (cache lock). The
 * administrators (and admin bots) become members with the usual role
 * suggestion and member_status "admin" / "creator". Payload: the group id
 * only. A Telegram error is dropped (the next refresh retries); nothing is
 * logged.
 */
class FetchGroupAdministrators implements ShouldQueueAfterCommit
{
    use Queueable;

    public const INTERVAL_MINUTES = 10;

    public int $tries = 1;

    public int $timeout = 20;

    public function __construct(public int $groupId) {}

    /**
     * Queue a fetch for a tracked group unless one ran in the last 10 minutes.
     */
    public static function dispatchFor(TelegramGroup $group): bool
    {
        $key = 'office:group-admins:'.$group->id;

        if (! $group->isTracked() || Cache::has($key)) {
            return false;
        }

        // The throttle key is taken only after the surrounding transaction committed, so a rollback does not burn the window.
        DB::afterCommit(function () use ($group, $key): void {
            if (Cache::add($key, true, now()->addMinutes(self::INTERVAL_MINUTES))) {
                dispatch(new self($group->id));
            }
        });

        return true;
    }

    public function handle(TelegramClient $client, UpsertGroupMember $members): void
    {
        $group = TelegramGroup::query()->find($this->groupId);

        // M1: no HTTP call unless group ingest is on, the group is tracked and the bot is still in it.
        if (! OfficeSettings::groupsEnabled()
            || ! $group instanceof TelegramGroup
            || ! $group->isTracked()
            || in_array($group->bot_status, ['left', 'kicked'], true)
            || ! $client->isConfigured()) {
            return;
        }

        try {
            $admins = $client->withTimeout(10, 5)->getChatAdministrators((string) $group->telegram_chat_id);
        } catch (TelegramException) {
            return;
        }

        foreach (array_slice($admins, 0, 100) as $admin) {
            $person = GroupUpdate::person($admin['user'] ?? null);

            if ($person !== null) {
                $members->handle($group, $person, is_string($admin['status'] ?? null) ? $admin['status'] : null);
            }
        }
    }
}
