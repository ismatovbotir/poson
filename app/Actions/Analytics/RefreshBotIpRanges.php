<?php

namespace App\Actions\Analytics;

use App\Support\Analytics\BotIpRanges;
use App\Support\Analytics\BotRegistry;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Download the published crawler IP ranges (config/ai-bots.php ip_ranges)
 * into storage/app/bot-ranges.json for BotVerifier. Weekly. A list that
 * fails to download keeps that bot's previous ranges. Shared URLs (one
 * Anthropic list for three bots) are fetched once. https only (also for
 * redirects, at most config analytics.verification.max_redirects), and a
 * body larger than max_body_bytes is refused.
 */
class RefreshBotIpRanges
{
    public function __construct(protected BotIpRanges $ranges) {}

    /**
     * @return array{updated: array<string, int>, failed: list<string>}
     */
    public function handle(): array
    {
        $previous = $this->ranges->all()['bots'];
        $fetched = [];
        $bots = [];
        $updated = [];
        $failed = [];

        foreach (BotRegistry::trackable() as $bot) {
            if ($bot->ipRangeUrls === []) {
                continue;
            }

            $cidrs = [];
            $complete = true;

            foreach ($bot->ipRangeUrls as $url) {
                // array_key_exists, not ??=: a failed (null) download must not be retried per bot.
                if (! array_key_exists($url, $fetched)) {
                    $fetched[$url] = $this->fetch($url);
                }

                if ($fetched[$url] === null) {
                    $complete = false;

                    continue;
                }

                array_push($cidrs, ...$fetched[$url]);
            }

            if ($complete && $cidrs !== []) {
                $bots[$bot->key] = array_values(array_unique($cidrs));
                $updated[$bot->key] = count($bots[$bot->key]);

                continue;
            }

            $failed[] = $bot->key;

            if (($previous[$bot->key] ?? []) !== []) {
                $bots[$bot->key] = $previous[$bot->key];
            }
        }

        $this->ranges->save($bots);

        return ['updated' => $updated, 'failed' => $failed];
    }

    /**
     * CIDRs from a {"prefixes": [{"ipv4Prefix": …}, {"ipv6Prefix": …}]} document, or null on failure.
     *
     * @return list<string>|null
     */
    protected function fetch(string $url): ?array
    {
        if (! str_starts_with(strtolower($url), 'https://')) {
            return null;
        }

        $maxBytes = (int) config('analytics.verification.max_body_bytes', 2 * 1024 * 1024);

        try {
            $response = Http::timeout((int) config('analytics.verification.http_timeout', 10))
                ->withOptions([
                    'allow_redirects' => [
                        'max' => (int) config('analytics.verification.max_redirects', 3),
                        'protocols' => ['https'],
                        'strict' => true,
                    ],
                    // Refuse a declared oversize body before downloading it.
                    'on_headers' => function ($response) use ($maxBytes): void {
                        if ((int) $response->getHeaderLine('Content-Length') > $maxBytes) {
                            throw new \RuntimeException('Bot IP range list is too large.');
                        }
                    },
                ])
                ->retry(2, 500, throw: false)
                ->acceptJson()
                ->get($url);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if (! $response->successful() || strlen($response->body()) > $maxBytes) {
            return null;
        }

        $prefixes = $response->json('prefixes');

        if (! is_array($prefixes)) {
            return null;
        }

        $cidrs = [];

        foreach ($prefixes as $prefix) {
            foreach (['ipv4Prefix', 'ipv6Prefix'] as $key) {
                $value = is_array($prefix) ? ($prefix[$key] ?? null) : null;

                if (is_string($value) && BotIpRanges::isCidr(trim($value))) {
                    $cidrs[] = trim($value);
                }
            }
        }

        return $cidrs === [] ? null : $cidrs;
    }
}
