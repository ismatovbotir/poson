<?php

namespace App\Actions\Office;

use App\Casts\EncryptedText;
use App\Enums\AgentRole;
use App\Enums\AnalysisStatus;
use App\Models\AgentProfile;
use App\Models\AgentProfileVersion;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupAnalysis;
use App\Models\TelegramGroupMessage;
use App\Models\TelegramGroupThread;
use App\Rules\BrandSafeText;
use App\Services\Ai\AiResult;
use App\Services\Ai\ChatModel;
use App\Support\Office\AnalysisOutput;
use App\Support\Office\CoreRules;
use App\Support\Office\GroupPrompt;
use App\Support\Office\GroupTranscript;
use App\Support\Office\OfficeLock;
use App\Support\Office\OfficePrompt;
use App\Support\Office\OfficeSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Run one queued group analysis (plan 44 §2, OG-4, OG-5, OG-6, OG-11, M2),
 * built on the plan 41 pipeline (CallOfficeModel, AnalysisOutput, CoreRules):
 *
 *  1. The group's OfficeLock (non-blocking; held → skipped "locked"); the
 *     row moves queued → running only once; the gates
 *     (QueueGroupAnalysis::isAnalysable) else skipped, no call.
 *  2. GroupTranscript (structured, random labels, redacted) and the input
 *     hash (message ids + edit stamps, member roles, customer link,
 *     published version ids, CoreRules and GroupPrompt versions): equal to
 *     the last done run → skipped, no call.
 *  3. Router (Haiku, vo_route, reserved): at most 3 threads; each is
 *     validated by AnalysisOutput; its client_labels are kept only when
 *     they are labels of THIS run that stand for a client member (an
 *     unknown, staff or invented label is dropped; a thread without a
 *     client is dropped). The AI never sets a role, assignment or customer.
 *  4. Threads: a customer-linked group is ONE thread (the strongest sales /
 *     service thread, all its clients); otherwise one thread per client
 *     member. At most 3 agent calls (Sonnet, the role's vo_* channel,
 *     reserved), sales and service threads only; support / other keep the
 *     router's topic and summary. Every output field passes AiOutputFilter
 *     (AnalysisOutput) and BrandSafeText; a blocked draft is draft_blocked.
 *     A capped call → the run is capped, due tomorrow, nothing saved.
 *  5. Save (M2), under the group row lock: the gates again, and every
 *     message id the transcript used must still exist; a Forget meanwhile →
 *     skipped "forgotten", nothing saved. Then the threads (encrypted, with
 *     member_ids) and the lead sync (SyncLeadFromGroupThread); threads no
 *     longer found are deleted (no lead) or lose their draft (with a lead).
 *     The run saves only while its row is still running (L3).
 *  6. Retries (L4): a "locked" skip is due again in 2 minutes and gives its
 *     daily-cap slot back; a failed run is due again in 15 minutes while
 *     the daily cap allows.
 * Logs: ids, outcome and tokens only.
 */
class RunGroupAnalysis
{
    public const MAX_AGENTS = 3;

    public function __construct(
        protected CallOfficeModel $modelCall,
        protected SyncLeadFromGroupThread $syncLead,
    ) {}

    public function handle(int $analysisId, ChatModel $model): void
    {
        $analysis = TelegramGroupAnalysis::query()->find($analysisId);

        if ($analysis === null || $analysis->status !== AnalysisStatus::Queued) {
            return;
        }

        $lock = OfficeLock::forGroup($analysis->telegram_group_id);

        if (! $lock->get()) {
            $this->finish($analysis, AnalysisStatus::Skipped, ['error' => 'locked']);
            // Forget (or another run) holds the lock: try again soon, and this run does not use the daily cap (L4).
            $this->retryLater($analysis->telegram_group_id, 2, refund: true);

            return;
        }

        $outcome = 'error';

        try {
            $outcome = $this->run($analysis, $model);
        } catch (Throwable $error) {
            $this->finish($analysis, AnalysisStatus::Failed, ['error' => 'error']);
            $outcome = 'error:'.class_basename($error);
        } finally {
            $lock->release();

            // A failed run (api_error, invalid_*, error …) is due again in 15 minutes, within the daily cap (L4).
            if (TelegramGroupAnalysis::query()->whereKey($analysis->id)->value('status') === AnalysisStatus::Failed) {
                $this->retryLater($analysis->telegram_group_id, 15, refund: false);
            }

            Log::info('office.group_analysis', [
                'analysis_id' => $analysis->id,
                'group_id' => $analysis->telegram_group_id,
                'outcome' => $outcome,
                'input_tokens' => $analysis->input_tokens,
                'output_tokens' => $analysis->output_tokens,
            ]);
        }
    }

    protected function run(TelegramGroupAnalysis $analysis, ChatModel $model): string
    {
        $claimed = TelegramGroupAnalysis::query()->whereKey($analysis->id)->where('status', AnalysisStatus::Queued->value)
            ->update(['status' => AnalysisStatus::Running->value, 'started_at' => now()]);

        if ($claimed !== 1) {
            return 'claimed';
        }

        $analysis->refresh();

        $group = TelegramGroup::query()->find($analysis->telegram_group_id);

        if (! $group instanceof TelegramGroup || ! QueueGroupAnalysis::isAnalysable($group)) {
            return $this->skip($analysis, 'gated');
        }

        $transcript = GroupTranscript::build($group);

        if ($transcript->isEmpty()) {
            return $this->skip($analysis, 'empty');
        }

        $profiles = AgentProfile::query()->with('currentVersion')->get()->keyBy(fn (AgentProfile $profile): string => $profile->role->value);
        $router = $profiles->get(AgentRole::Router->value)?->currentVersion;

        if (! $router instanceof AgentProfileVersion) {
            return $this->skip($analysis, 'no_profile');
        }

        $hash = $this->hash($group, $transcript, $profiles->map(fn (AgentProfile $profile): ?int => $profile->current_version_id)->all());
        $last = $group->analyses()->where('status', AnalysisStatus::Done->value)->whereKeyNot($analysis->id)->latest('id')->first();

        if ($last !== null && $last->input_hash === $hash) {
            return $this->skip($analysis, 'unchanged', ['input_hash' => $hash, 'up_to_message_id' => $transcript->upToMessageId]);
        }

        $output = AnalysisOutput::make();
        $tokens = ['input' => 0, 'output' => 0, 'cache' => 0];
        $models = [];
        $base = ['router_version_id' => $router->id, 'input_hash' => $hash, 'up_to_message_id' => $transcript->upToMessageId, 'member_ids' => $transcript->memberIds()];

        // Router.
        $routed = $this->modelCall->handle($model, AgentRole::Router->usageChannel(), GroupPrompt::router($router, $transcript), $tokens, $models, 'router');

        if (is_string($routed)) {
            return $this->stop($analysis, $group, $routed, $base + $this->usageColumns($tokens, $models));
        }

        $route = $this->route($output, $routed, $transcript);

        if ($route === null) {
            $this->finish($analysis, AnalysisStatus::Failed, ['error' => 'invalid_router'] + $base + $this->usageColumns($tokens, $models));

            return 'invalid_router';
        }

        $plans = $this->plans($group, $route['threads']);

        // Agents: one call per sales / service thread, at most MAX_AGENTS.
        $agentCalls = 0;

        foreach ($plans as $index => $plan) {
            $agent = in_array($plan['role'], [AgentRole::Sales, AgentRole::Service], true) ? $profiles->get($plan['role']->value)?->currentVersion : null;

            // plans() already returns at most MAX_THREADS (= MAX_AGENTS) threads; kept as a defensive guard for OG-11.
            if (! $agent instanceof AgentProfileVersion || $agentCalls >= self::MAX_AGENTS) {
                continue;
            }

            $agentCalls++;
            $previous = $this->previousSummary($group, $plan['client_member_id']);
            $answered = $this->modelCall->handle($model, $plan['role']->usageChannel(), GroupPrompt::agent($agent, $transcript, $previous, $plan['topic'], $transcript->labelsFor($plan['member_ids']), $plan['client_member_id'] === null, $plan['role']), $tokens, $models, 'agent'.($index + 1));

            if (is_string($answered)) {
                return $this->stop($analysis, $group, $answered, $base + $this->usageColumns($tokens, $models));
            }

            $result = $output->agent($answered->text, $agent->guidanceFor($plan['role']), ['client_text' => $transcript->clientText($plan['member_ids'], $last?->up_to_message_id), 'confidence' => $plan['confidence'], 'language' => $route['language']]);

            if ($result === null) {
                $this->finish($analysis, AnalysisStatus::Failed, ['error' => 'invalid_agent'] + $base + $this->usageColumns($tokens, $models));

                return 'invalid_agent';
            }

            $plans[$index] = [
                ...$plan,
                'agent_version_id' => $agent->id,
                'summary' => self::brandSafe($result['summary']) ?? $plan['summary'],
                'next_step' => self::brandSafe($result['next_step']),
                'draft' => $result['draft'],
                'draft_blocked' => $result['draft_blocked'],
                'needs_human' => $result['needs_human'],
                'escalation' => $result['escalation'],
                'draft_hold' => $result['draft_hold'],
                'lead_fields' => $result['lead'],
            ];
        }

        return $this->save($analysis, $transcript, $plans, $route['language'], $base + $this->usageColumns($tokens, $models));
    }

    /**
     * The validated router answer: language and at most 3 threads with client members of this run only.
     *
     * @return array{language: ?string, threads: list<array{role: AgentRole, topic: ?string, confidence: int, summary: ?string, member_ids: list<int>}>}|null
     */
    protected function route(AnalysisOutput $output, AiResult $result, GroupTranscript $transcript): ?array
    {
        $data = AnalysisOutput::decode($result->text);

        if ($data === null || ! is_array($data['threads'] ?? null) || ! array_is_list($data['threads'])) {
            return null;
        }

        $language = is_string($data['language'] ?? null) && in_array($data['language'], OfficePrompt::LANGUAGES, true) ? $data['language'] : null;
        $threads = [];

        foreach (array_slice($data['threads'], 0, GroupPrompt::MAX_THREADS) as $thread) {
            if (! is_array($thread)) {
                continue;
            }

            $valid = $output->routerData([...$thread, 'language' => $language, 'needs_reply' => true]);

            if ($valid === null) {
                continue;
            }

            $memberIds = [];

            foreach (array_slice(is_array($thread['client_labels'] ?? null) ? $thread['client_labels'] : [], 0, 10) as $label) {
                $memberId = $transcript->clientMemberId($label);

                if ($memberId !== null && ! in_array($memberId, $memberIds, true)) {
                    $memberIds[] = $memberId;
                }
            }

            // A thread with no client of this run is dropped (an unknown, staff or invented label, OG-5).
            if ($memberIds === []) {
                continue;
            }

            $threads[] = ['role' => $valid['role'], 'topic' => $valid['topic'], 'confidence' => $valid['confidence'], 'summary' => self::brandSafe($valid['summary']), 'member_ids' => $memberIds];
        }

        return ['language' => $language, 'threads' => $threads];
    }

    /**
     * Threads to store: a customer-linked group is one thread; otherwise one per client member (max 3).
     *
     * @param  list<array{role: AgentRole, topic: ?string, confidence: int, summary: ?string, member_ids: list<int>}>  $threads
     * @return list<array<string, mixed>>
     */
    protected function plans(TelegramGroup $group, array $threads): array
    {
        $blank = ['next_step' => null, 'draft' => null, 'draft_blocked' => false, 'needs_human' => false, 'escalation' => null, 'draft_hold' => null, 'lead_fields' => [], 'agent_version_id' => null];

        if ($threads === []) {
            return [];
        }

        if ($group->customer_id !== null) {
            usort($threads, fn (array $a, array $b): int => [(int) ! in_array($a['role'], [AgentRole::Sales, AgentRole::Service], true), -$a['confidence']]
                <=> [(int) ! in_array($b['role'], [AgentRole::Sales, AgentRole::Service], true), -$b['confidence']]);
            $memberIds = array_values(array_unique(array_merge(...array_column($threads, 'member_ids'))));

            return [[...$threads[0], ...$blank, 'client_member_id' => null, 'member_ids' => $memberIds]];
        }

        $plans = [];

        foreach ($threads as $thread) {
            foreach ($thread['member_ids'] as $memberId) {
                if (! isset($plans[$memberId]) && count($plans) < GroupPrompt::MAX_THREADS) {
                    $plans[$memberId] = [...$thread, ...$blank, 'client_member_id' => $memberId, 'member_ids' => [$memberId]];
                }
            }
        }

        return array_values($plans);
    }

    /**
     * Store the threads only while the group may still be analysed and every message used still exists (M2).
     *
     * @param  list<array<string, mixed>>  $plans
     * @param  array<string, mixed>  $values
     */
    protected function save(TelegramGroupAnalysis $analysis, GroupTranscript $transcript, array $plans, ?string $language, array $values): string
    {
        return DB::transaction(function () use ($analysis, $transcript, $plans, $language, $values): string {
            $group = TelegramGroup::query()->whereKey($analysis->telegram_group_id)->lockForUpdate()->first();

            if (! $group instanceof TelegramGroup || ! QueueGroupAnalysis::isAnalysable($group)) {
                $this->finish($analysis, AnalysisStatus::Skipped, ['error' => 'gated']);

                return 'gated';
            }

            // L3: the stale rule (or anything else) may have closed this run meanwhile: save only while it is running.
            $status = TelegramGroupAnalysis::query()->whereKey($analysis->id)->lockForUpdate()->value('status');

            if ($status !== AnalysisStatus::Running) {
                return 'not_running';
            }

            $still = TelegramGroupMessage::query()->where('telegram_group_id', $group->id)->whereKey($transcript->messageIds)->count();

            if ($still !== count($transcript->messageIds)) {
                $this->finish($analysis, AnalysisStatus::Skipped, ['error' => 'forgotten']);

                return 'forgotten';
            }

            $first = $plans[0] ?? null;

            $analysis->forceFill([...$values,
                'role' => $first['role'] ?? null,
                'topic' => $first['topic'] ?? null,
                'confidence' => $first['confidence'] ?? null,
                'language' => $language,
                'summary' => $first['summary'] ?? null,
                'next_step' => $first['next_step'] ?? null,
                'draft_reply' => $first['draft'] ?? null,
                'draft_blocked' => (bool) ($first['draft_blocked'] ?? false),
                'lead_fields' => $first !== null && $first['lead_fields'] !== [] ? $first['lead_fields'] : null,
                'agent_version_id' => $first['agent_version_id'] ?? null,
                'lead_action' => TelegramGroupAnalysis::LEAD_NONE,
                'status' => AnalysisStatus::Done, 'error' => null, 'finished_at' => now(),
            ])->save();

            $group->forceFill(['last_analysed_message_id' => $analysis->up_to_message_id])->save();

            foreach ($plans as $index => $plan) {
                $thread = TelegramGroupThread::query()->where('telegram_group_id', $group->id)->where('client_member_id', $plan['client_member_id'])->lockForUpdate()->first()
                    ?? (new TelegramGroupThread)->forceFill(['telegram_group_id' => $group->id, 'client_member_id' => $plan['client_member_id']]);

                $thread->forceFill([
                    'role' => $plan['role'],
                    'topic' => $plan['topic'],
                    'summary' => $plan['summary'],
                    'next_step' => $plan['next_step'],
                    'draft' => $plan['draft'],
                    'draft_blocked' => $plan['draft_blocked'],
                    'needs_human' => $plan['needs_human'],
                    'escalation' => $plan['escalation'],
                    'draft_hold' => $plan['draft_hold'],
                    'agent_version_id' => $plan['agent_version_id'],
                    'member_ids' => $plan['member_ids'],
                    'customer_id' => $thread->customer_id ?? $group->customer_id,
                    'last_analysis_id' => $analysis->id,
                ])->save();

                $lead = $this->syncLead->handle($group, $thread, $analysis, $plan['role'], $plan['topic'], $language, $plan['member_ids'], (array) $plan['lead_fields']);

                if ($index === 0) {
                    $analysis->forceFill(['lead_action' => $lead['action'], 'lead_id' => $lead['lead_id']])->save();
                }
            }

            $this->retireStaleThreads($group, $analysis);

            return 'done';
        });
    }

    /**
     * Threads this run no longer found (code review 4): without a lead they are deleted; with a lead they
     * stay as the lead's link, but their draft is cleared so an old reply is never offered again.
     */
    protected function retireStaleThreads(TelegramGroup $group, TelegramGroupAnalysis $analysis): void
    {
        $stale = TelegramGroupThread::query()->where('telegram_group_id', $group->id)
            ->where(fn ($query) => $query->whereNull('last_analysis_id')->orWhere('last_analysis_id', '!=', $analysis->id));

        (clone $stale)->whereNull('lead_id')->delete();
        (clone $stale)->whereNotNull('lead_id')->update(['draft' => null, 'draft_blocked' => false, 'draft_hold' => null, 'needs_human' => false, 'escalation' => null, 'updated_at' => now()]);
    }

    /**
     * Make the group due again in $minutes (never later than an earlier due time). refund: this run gives its
     * daily-cap slot back; otherwise nothing is scheduled once the daily cap is reached.
     */
    protected function retryLater(int $groupId, int $minutes, bool $refund): void
    {
        DB::transaction(function () use ($groupId, $minutes, $refund): void {
            $group = TelegramGroup::query()->whereKey($groupId)->lockForUpdate()->first();

            if (! $group instanceof TelegramGroup) {
                return;
            }

            $today = $group->analyses_day?->toDateString() === now('Asia/Tashkent')->toDateString();

            if ($refund && $today && $group->analyses_today > 0) {
                $group->analyses_today--;
            }

            if (! $refund && $today && $group->analyses_today >= OfficeSettings::perChatDaily()) {
                $group->save();

                return;
            }

            $due = now()->addMinutes($minutes);

            if ($group->analysis_due_at === null || $group->analysis_due_at->greaterThan($due)) {
                $group->analysis_due_at = $due;
            }

            $group->save();
        });
    }

    /**
     * Summary / next step: dropped when BrandSafeText finds a price or discount promise (AiOutputFilter already ran).
     */
    protected static function brandSafe(?string $text): ?string
    {
        return $text !== null && BrandSafeText::violates($text, BrandSafeText::CAREERS) ? null : $text;
    }

    protected function previousSummary(TelegramGroup $group, ?int $memberId): ?string
    {
        $summary = TelegramGroupThread::query()->where('telegram_group_id', $group->id)->where('client_member_id', $memberId)->value('summary');

        return EncryptedText::isReadable($summary) ? (string) $summary : null;
    }

    /**
     * A call that did not answer: capped → due again tomorrow (Tashkent), nothing saved; anything else → failed.
     *
     * @param  array<string, mixed>  $values
     */
    protected function stop(TelegramGroupAnalysis $analysis, TelegramGroup $group, string $outcome, array $values): string
    {
        if ($outcome === 'capped') {
            $this->finish($analysis, AnalysisStatus::Capped, ['error' => 'capped'] + $values);
            $group->forceFill(['analysis_due_at' => QueueBusinessChatAnalysis::tomorrow()])->save();

            return 'capped';
        }

        $this->finish($analysis, AnalysisStatus::Failed, ['error' => mb_substr($outcome, 0, 40)] + $values);

        return $outcome;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function skip(TelegramGroupAnalysis $analysis, string $reason, array $values = []): string
    {
        $this->finish($analysis, AnalysisStatus::Skipped, ['error' => $reason] + $values);

        return $reason;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function finish(TelegramGroupAnalysis $analysis, AnalysisStatus $status, array $values = []): void
    {
        $analysis->forceFill([...$values, 'status' => $status, 'finished_at' => now()])->save();
    }

    /**
     * @param  array<string, ?int>  $versionIds
     */
    protected function hash(TelegramGroup $group, GroupTranscript $transcript, array $versionIds): string
    {
        ksort($versionIds);
        $roles = [];

        foreach ($transcript->labels as $entry) {
            $roles[$entry['member_id']] = $entry['role']->value;
        }

        ksort($roles);

        return hash('sha256', (string) json_encode([$transcript->stamps, $roles, $group->customer_id, $versionIds, CoreRules::VERSION, GroupPrompt::VERSION, OfficePrompt::FORMAT_VERSION]));
    }

    /**
     * @param  array{input: int, output: int, cache: int}  $tokens
     * @param  array<string, string>  $models
     * @return array<string, mixed>
     */
    protected function usageColumns(array $tokens, array $models): array
    {
        return ['input_tokens' => $tokens['input'], 'output_tokens' => $tokens['output'], 'cache_read_tokens' => $tokens['cache'], 'models' => $models === [] ? null : $models];
    }
}
