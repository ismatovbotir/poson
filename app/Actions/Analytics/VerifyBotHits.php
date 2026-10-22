<?php

namespace App\Actions\Analytics;

use App\Support\Analytics\BotDefinition;
use App\Support\Analytics\BotRegistry;
use App\Support\Analytics\BotVerifier;
use App\Support\Analytics\IpAddress;
use App\Support\Analytics\TrafficRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Verify the crawler hits of one day (plan 04 S13): every distinct bot + IP
 * still unchecked is checked once by BotVerifier (published ranges or
 * forward-confirmed reverse DNS) and all its hits that day are marked
 * verified (true) or spoofed (false). User-Agent-only bots stay null.
 * Undecided pairs (DNS budget spent, ranges not loaded) stay null for the
 * next run. Run nightly through VerifyRecentBotHits (last days, then
 * re-aggregated).
 */
class VerifyBotHits
{
    public function __construct(protected BotVerifier $verifier) {}

    /**
     * @return array{checked: int, verified: int, spoofed: int, undecided: int}
     */
    public function handle(string $date): array
    {
        [$from, $to] = TrafficRecorder::dayBounds(CarbonImmutable::parse($date, TrafficRecorder::timezone())->toDateString());

        $verifiable = array_keys(array_filter(BotRegistry::trackable(), fn (BotDefinition $bot): bool => $bot->isVerifiable()));
        $stats = ['checked' => 0, 'verified' => 0, 'spoofed' => 0, 'undecided' => 0];

        if ($verifiable === []) {
            return $stats;
        }

        $pairs = DB::table('bot_hits')
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $to)
            ->whereNull('verified')
            ->whereNotNull('ip')
            ->whereIn('bot', $verifiable)
            ->distinct()
            ->get(['bot', 'ip']);

        foreach ($pairs as $pair) {
            $bot = BotRegistry::find((string) $pair->bot);
            $ip = IpAddress::unpack($pair->ip);

            if ($bot === null) {
                continue;
            }

            $verdict = $ip === null ? false : $this->verifier->verify($bot, $ip);
            $stats['checked']++;

            if ($verdict === null) {
                $stats['undecided']++;

                continue;
            }

            $stats[$verdict ? 'verified' : 'spoofed']++;

            DB::table('bot_hits')
                ->where('occurred_at', '>=', $from)
                ->where('occurred_at', '<', $to)
                ->where('bot', $bot->key)
                ->where('ip', $pair->ip)
                ->whereNull('verified')
                ->update(['verified' => $verdict]);
        }

        return $stats;
    }
}
