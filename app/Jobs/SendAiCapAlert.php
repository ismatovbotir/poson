<?php

namespace App\Jobs;

use App\Enums\AiUsageChannel;
use App\Support\Monitoring\MonitoringAlerts;
use App\Support\Telegram\TelegramLocale;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\App;

/**
 * Counts-only alert to the management chat: a daily AI cap was reached
 * (plan 30 §8, AI-B13). Queued by RecordAiUsage after commit, at most once
 * per channel and Asia/Tashkent day; carries the channel and the day's
 * counters only, never a question, answer or customer.
 */
class SendAiCapAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public string $channel,
        public int $requests,
        public int $tokens,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $previous = App::getLocale();

        try {
            // The channel's label in the alert language (AiUsageChannel::label() reads the app locale).
            App::setLocale(TelegramLocale::current());
            $channel = AiUsageChannel::tryFrom($this->channel)?->label() ?? $this->channel;
        } finally {
            App::setLocale($previous);
        }

        MonitoringAlerts::problem('ai_daily_cap', ['channel' => $channel, 'requests' => $this->requests, 'tokens' => $this->tokens]);
    }
}
