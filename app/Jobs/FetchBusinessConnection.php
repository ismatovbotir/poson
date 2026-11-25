<?php

namespace App\Jobs;

use App\Actions\Office\UpsertBusinessConnection;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Telegram\Bot\BusinessUpdate;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * getBusinessConnection for a connection id the webhook saw in a message
 * but has no row for (plan 41 §3.2): dispatched at most once per id and
 * hour and within a global hourly budget (OfficeLimits, VO-3). The only
 * outbound business call of phase 1 (VO-2). Payload: the connection id
 * only. A Telegram error is dropped (the next message retries after an
 * hour); nothing is logged.
 */
class FetchBusinessConnection implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 20;

    public function __construct(public string $connectionId) {}

    public function handle(TelegramClient $client, UpsertBusinessConnection $upsert): void
    {
        if (preg_match(BusinessUpdate::CONNECTION_ID_PATTERN, $this->connectionId) !== 1 || ! $client->isConfigured()) {
            return;
        }

        try {
            $connection = $client->withTimeout(10, 5)->getBusinessConnection($this->connectionId);
        } catch (TelegramException) {
            return;
        }

        $fields = BusinessUpdate::connectionFields($connection);

        // Only the connection that was asked for.
        if ($fields['connectionId'] !== $this->connectionId) {
            return;
        }

        DB::transaction(fn () => $upsert->handle($fields));
    }
}
