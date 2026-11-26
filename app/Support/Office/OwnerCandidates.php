<?php

namespace App\Support\Office;

use App\Models\BusinessConnection;
use App\Models\TelegramUser;
use App\Support\Search;
use App\Support\Telegram\Bot\BotText;
use Illuminate\Support\Carbon;

/**
 * Accounts the Admin may pick as Virtual office owners (plan 44 §1, OG-3),
 * searched on the server:
 *
 *  1. owners of business_connections ("tried to connect"), last seen = updated_at;
 *  2. telegram_users that are not banned ("bot user"), last seen = last_inbound_at.
 *
 * A term needs at least 2 characters; LIKE wildcards are escaped; at most 20
 * results. Only the first name, @username, Telegram id, source and last seen
 * are read and returned: never the phone, the customer link or the bot
 * registration data. Each candidate's name comes from its own row only.
 */
final class OwnerCandidates
{
    public const SOURCE_CONNECTION = 'connection';

    public const SOURCE_BOT_USER = 'bot_user';

    public const MIN_TERM = 2;

    public const LIMIT = 20;

    /**
     * @param  list<int>  $exclude  Ids already selected
     * @return list<array{telegram_id: int, first_name: ?string, username: ?string, source: string, last_seen: ?Carbon}>
     */
    public static function search(string $term, array $exclude = []): array
    {
        $term = ltrim(BotText::clean($term), '@');

        if (mb_strlen($term) < self::MIN_TERM || mb_strlen($term) > 64) {
            return [];
        }

        $pattern = Search::likePattern($term);
        $id = ctype_digit($term) ? filter_var($term, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

        $owners = BusinessConnection::query()
            ->where(function ($query) use ($pattern, $id): void {
                $query->whereRaw("lower(owner_first_name) like ? escape '".Search::ESCAPE."'", [$pattern])
                    ->orWhereRaw("lower(owner_username) like ? escape '".Search::ESCAPE."'", [$pattern]);

                if ($id !== false) {
                    $query->orWhere('owner_telegram_id', $id);
                }
            })
            ->when($exclude !== [], fn ($query) => $query->whereNotIn('owner_telegram_id', $exclude))
            ->groupBy('owner_telegram_id')
            ->orderByRaw('max(updated_at) desc')
            ->limit(self::LIMIT)
            ->pluck('owner_telegram_id')
            ->all();

        // One owner = one candidate (many reconnects never crowd out others); the profile of its newest row.
        $connections = $owners === [] ? collect() : BusinessConnection::query()
            ->whereIn('owner_telegram_id', $owners)
            ->latest('updated_at')
            ->get(['owner_telegram_id', 'owner_first_name', 'owner_username', 'updated_at'])
            ->unique('owner_telegram_id')
            ->sortBy(fn (BusinessConnection $connection): int => array_search($connection->owner_telegram_id, $owners, true))
            ->values();

        $botUsers = TelegramUser::query()
            ->whereNull('banned_at')
            ->where(function ($query) use ($pattern, $id): void {
                $query->whereRaw("lower(first_name) like ? escape '".Search::ESCAPE."'", [$pattern])
                    ->orWhereRaw("lower(username) like ? escape '".Search::ESCAPE."'", [$pattern]);

                if ($id !== false) {
                    $query->orWhere('telegram_id', $id);
                }
            })
            ->when($exclude !== [], fn ($query) => $query->whereNotIn('telegram_id', $exclude))
            ->orderByDesc('last_inbound_at')
            ->limit(self::LIMIT)
            ->get(['telegram_id', 'first_name', 'username', 'last_inbound_at']);

        return array_slice(array_values(self::merge($connections->all(), $botUsers->all())), 0, self::LIMIT);
    }

    /**
     * The selected ids with what is known about each (id order kept); an
     * unknown id has source null and no name ("Unknown account #id").
     *
     * @param  list<int>  $ids
     * @return list<array{telegram_id: int, first_name: ?string, username: ?string, source: ?string, last_seen: ?Carbon}>
     */
    public static function resolve(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $known = self::merge(
            BusinessConnection::query()->whereIn('owner_telegram_id', $ids)->latest('updated_at')->get(['owner_telegram_id', 'owner_first_name', 'owner_username', 'updated_at'])->all(),
            // No ban filter: a selected owner who is banned in the bot still shows a name.
            TelegramUser::query()->whereIn('telegram_id', $ids)->get(['telegram_id', 'first_name', 'username', 'last_inbound_at'])->all(),
        );

        return array_map(fn (int $id): array => $known[$id] ?? ['telegram_id' => $id, 'first_name' => null, 'username' => null, 'source' => null, 'last_seen' => null], $ids);
    }

    /**
     * One entry per Telegram id: a connection (newest row first) wins over a bot user.
     *
     * @param  list<BusinessConnection>  $connections
     * @param  list<TelegramUser>  $botUsers
     * @return array<int, array{telegram_id: int, first_name: ?string, username: ?string, source: string, last_seen: ?Carbon}>
     */
    private static function merge(array $connections, array $botUsers): array
    {
        $candidates = [];

        foreach ($connections as $connection) {
            $candidates[$connection->owner_telegram_id] ??= [
                'telegram_id' => $connection->owner_telegram_id,
                'first_name' => $connection->owner_first_name,
                'username' => $connection->owner_username,
                'source' => self::SOURCE_CONNECTION,
                'last_seen' => $connection->updated_at,
            ];
        }

        foreach ($botUsers as $user) {
            $candidates[$user->telegram_id] ??= [
                'telegram_id' => $user->telegram_id,
                'first_name' => $user->first_name,
                'username' => $user->username,
                'source' => self::SOURCE_BOT_USER,
                'last_seen' => $user->last_inbound_at,
            ];
        }

        return $candidates;
    }
}
