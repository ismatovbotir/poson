<?php

namespace App\Console\Commands;

use App\Actions\Office\DispatchDueAnalyses;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Plan 41 §6.1: queue the Virtual office analyses that are due (routes/console.php, every minute).
 */
#[Signature('office:analyse-due')]
#[Description('Queue Virtual office analyses of Telegram Business client chats that are due')]
class OfficeAnalyseDue extends Command
{
    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Virtual office (plan 41): finds client chats of the connected Telegram Business account whose analysis is due (10 minutes of silence by default, or "Analyse now"), and queues at most 10 of them, never more than 3 analyses queued or running at once, on the ai-admin queue.
Analyses stuck as queued or running longer than the job timeout + retry_after + 60 seconds are marked failed first.
Does nothing more while the office AI switch (Virtual office -> Settings) is off. Each analysis calls the AI model (Claude) and counts against the office daily caps; nothing is ever sent to Telegram.
Scheduled every minute; there is normally no reason to run it by hand.';

    public function handle(DispatchDueAnalyses $dispatch): int
    {
        $result = $dispatch->handle();

        $this->components->info($result['queued'].' analysis(es) queued, '.$result['stale'].' stale marked failed.');

        return self::SUCCESS;
    }
}
