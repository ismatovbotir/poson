<?php

namespace App\Actions\Telegram;

use App\Models\MonitoringState;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Monitoring\ProblemMonitor;
use App\Support\Telegram\Bot\BotSwitch;
use App\Support\Telegram\Bot\InboxChat;
use App\Support\Telegram\TelegramLocale;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Webhook health check behind `telegram:check-webhook` (plan 14 v2 D13,
 * TG-38), every 15 minutes. Alerts go through ProblemMonitor::track()
 * (MonitoringAlerts to the management chat, MonitoringState dedupe, hourly
 * reminder, recovered message).
 *
 * Problems (problem:telegram_webhook):
 *  - bot enabled: the URL differs from the expected one, a delivery error
 *    in the last 15 minutes, or more than 100 pending updates;
 *  - bot disabled: only a non-empty URL that differs from the expected one
 *    (someone holding the token moved the webhook).
 * A 401 from Telegram is its own problem (problem:telegram_token): the token
 * was revoked or is wrong.
 *
 * Without a token both problems are closed (SKIPPED). Open problems are
 * also listed on System → Monitoring and the bot page (openProblems()),
 * because a token alert can't be delivered with the revoked token (L4).
 *
 * Alerts carry only fixed, translated issue names and the pending count;
 * never the URL, last_error_message or ip_address.
 */
class CheckTelegramWebhook
{
    public const WEBHOOK_KEY = 'problem:telegram_webhook';

    public const TOKEN_KEY = 'problem:telegram_token';

    public const WEBHOOK_EVENT = 'telegram_webhook';

    public const TOKEN_EVENT = 'telegram_token';

    public const SNAPSHOT_KEY = 'telegram:status';

    public const ERROR_WINDOW = 900;

    public const PENDING_LIMIT = 100;

    public const OK = 'ok';

    public const PROBLEM = 'problem';

    public const SKIPPED = 'skipped';

    public const UNREACHABLE = 'unreachable';

    public function __construct(protected TelegramClient $client) {}

    /**
     * @return self::OK|self::PROBLEM|self::SKIPPED|self::UNREACHABLE
     */
    public function handle(int $timeout = 5): string
    {
        $info = null;
        $code = null;
        $result = $this->check($timeout, $info, $code);
        $this->writeSnapshot($result, $info, $code);

        return $result;
    }

    /**
     * @param  array<string, mixed>|null  $info
     * @return self::OK|self::PROBLEM|self::SKIPPED|self::UNREACHABLE
     */
    private function check(int $timeout, ?array &$info, ?int &$code): string
    {
        $monitor = ProblemMonitor::using($timeout);

        if (! $this->client->isConfigured()) {
            // No bot any more: close open problems (one "recovered" attempt each) instead of leaving them open forever.
            $monitor->track(self::TOKEN_KEY, false, self::TOKEN_EVENT, []);
            $monitor->track(self::WEBHOOK_KEY, false, self::WEBHOOK_EVENT, []);

            return self::SKIPPED;
        }

        try {
            $info = $this->client->withTimeout($timeout)->getWebhookInfo();
        } catch (TelegramException $exception) {
            $code = $exception->status;

            if ($exception->status === 401) {
                $monitor->track(self::TOKEN_KEY, true, self::TOKEN_EVENT, []);

                return self::PROBLEM;
            }

            return self::UNREACHABLE;
        }

        $monitor->track(self::TOKEN_KEY, false, self::TOKEN_EVENT, []);

        $issues = $this->issues($info, BotSwitch::isEnabled());
        $locale = TelegramLocale::current();

        $monitor->track(self::WEBHOOK_KEY, $issues !== [], self::WEBHOOK_EVENT, [
            'issue' => implode(', ', array_map(fn (string $issue): string => __('telegram.monitoring.webhook_issues.'.$issue, [], $locale), $issues)),
            'pending' => is_int($info['pending_update_count'] ?? null) ? $info['pending_update_count'] : null,
        ]);

        return $issues === [] ? self::OK : self::PROBLEM;
    }

    /**
     * The status panel's snapshot (plan 21 E1, T21-6): an exact allow-list of
     * safe fields in MonitoringState `telegram:status`. Never the URL, IP,
     * Telegram's error text, a chat id or title, the bot id or username.
     * A failed write is reported and never fails the check.
     *
     * @param  array<string, mixed>|null  $info
     */
    private function writeSnapshot(string $result, ?array $info, ?int $code): void
    {
        $expected = ConfigureTelegramWebhook::expectedUrl();
        $url = is_array($info) && is_string($info['url'] ?? null) ? $info['url'] : null;
        $lastError = is_array($info) && is_int($info['last_error_date'] ?? null) ? $info['last_error_date'] : null;

        $meta = [
            'checked_at' => now()->toIso8601String(),
            'webhook_match' => $url === null ? null : ($expected !== null && $url !== '' && hash_equals($expected, $url)),
            'last_error_at' => $lastError === null ? null : Carbon::createFromTimestamp($lastError)->toIso8601String(),
            'pending' => is_array($info) && is_int($info['pending_update_count'] ?? null) ? $info['pending_update_count'] : null,
            'get_me' => match (true) {
                $result === self::PROBLEM && $info === null => 'fail',
                $info !== null => 'ok',
                default => 'unknown',
            },
            'chat' => 'unknown',
            'chat_key' => InboxChat::key(),
            'code' => $code,
        ];

        try {
            MonitoringState::query()->updateOrCreate(['key' => self::SNAPSHOT_KEY], ['ticked_at' => now(), 'meta' => $meta]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Open Telegram problems for the admin screens: event => since.
     *
     * @return array<string, Carbon>
     */
    public static function openProblems(): array
    {
        $events = [self::TOKEN_KEY => self::TOKEN_EVENT, self::WEBHOOK_KEY => self::WEBHOOK_EVENT];

        return MonitoringState::query()
            ->whereIn('key', array_keys($events))
            ->whereNotNull('problem_since')
            ->get()
            ->mapWithKeys(fn (MonitoringState $state): array => [$events[$state->key] => $state->problem_since])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $info
     * @return list<string> url_mismatch | url_unexpected | delivery_errors | backlog
     */
    public function issues(array $info, bool $enabled): array
    {
        $expected = ConfigureTelegramWebhook::expectedUrl();
        $url = is_string($info['url'] ?? null) ? $info['url'] : '';
        $matches = $expected !== null && $url !== '' && hash_equals($expected, $url);

        if (! $enabled) {
            return $url !== '' && ! $matches ? ['url_unexpected'] : [];
        }

        $issues = [];

        if (! $matches) {
            $issues[] = 'url_mismatch';
        }

        $lastError = $info['last_error_date'] ?? null;

        if (is_int($lastError) && Carbon::createFromTimestamp($lastError)->gt(now()->subSeconds(self::ERROR_WINDOW))) {
            $issues[] = 'delivery_errors';
        }

        if (is_int($info['pending_update_count'] ?? null) && $info['pending_update_count'] > self::PENDING_LIMIT) {
            $issues[] = 'backlog';
        }

        return $issues;
    }
}
