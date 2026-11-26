<?php

namespace App\Actions\Office;

use App\Actions\Chat\AiKnowledgeHit;
use App\Actions\Chat\FindAiKnowledge;
use App\Casts\EncryptedText;
use App\Enums\AgentRole;
use App\Enums\AiUsageChannel;
use App\Enums\AnalysisStatus;
use App\Models\AgentProfile;
use App\Models\AgentProfileVersion;
use App\Models\BusinessChat;
use App\Models\BusinessChatAnalysis;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiResult;
use App\Services\Ai\ChatModel;
use App\Support\Office\AnalysisOutput;
use App\Support\Office\BusinessTranscript;
use App\Support\Office\CoreRules;
use App\Support\Office\OfficeLock;
use App\Support\Office\OfficePrompt;
use App\Support\Office\OfficeSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Run one queued analysis (plan 41 §6, the AnalyseBusinessChat job):
 *
 *  1. The chat's lock (non-blocking; held: skipped "locked"); the row moves
 *     queued → running only once. AI switch, key and the 401/403 pause are
 *     checked, else skipped (no call). The chat must be a client chat.
 *  2. The transcript (BusinessTranscript, redacted, VO-6) and the input hash
 *     (message ids, edit stamps, the published version ids, CoreRules
 *     version): equal to the last done analysis → skipped, no call.
 *  3. Router (Haiku, vo_route): reserve before the call, settle in finally
 *     (VO-11); over the cap → capped and the chat is due again at the start
 *     of the next Tashkent day.
 *  4. sales / service / support → that role's agent (Sonnet, own channel),
 *     with up to 5 site passages when the profile uses site knowledge;
 *     "other" → router only, no draft.
 *  5. Validated in PHP (AnalysisOutput, VO-7, VO-8).
 *  6. Under the same lock, the chat is re-read: still a client chat (VO-12),
 *     else nothing is saved. Done + tokens + version ids, then the lead
 *     sync (SyncLeadFromBusinessChat, sales and service only).
 * Every Throwable ends as failed "error". Logs: ids, status, role, tokens only.
 */
class RunBusinessChatAnalysis
{
    public function __construct(
        protected FindAiKnowledge $knowledge,
        protected SyncLeadFromBusinessChat $syncLead,
        protected CallOfficeModel $modelCall,
    ) {}

    public function handle(int $analysisId, ChatModel $model): void
    {
        $analysis = BusinessChatAnalysis::query()->find($analysisId);

        if ($analysis === null || $analysis->status !== AnalysisStatus::Queued) {
            return;
        }

        $lock = OfficeLock::forChat($analysis->business_chat_id);

        if (! $lock->get()) {
            $this->finish($analysis, AnalysisStatus::Skipped, ['error' => 'locked']);

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

            Log::info('office.analysis', [
                'analysis_id' => $analysis->id,
                'chat_id' => $analysis->business_chat_id,
                'outcome' => $outcome,
                'input_tokens' => $analysis->input_tokens,
                'output_tokens' => $analysis->output_tokens,
            ]);
        }
    }

    protected function run(BusinessChatAnalysis $analysis, ChatModel $model): string
    {
        $claimed = BusinessChatAnalysis::query()->whereKey($analysis->id)->where('status', AnalysisStatus::Queued->value)
            ->update(['status' => AnalysisStatus::Running->value, 'started_at' => now()]);

        if ($claimed !== 1) {
            return 'claimed';
        }

        $analysis->refresh();

        if (! OfficeSettings::aiEnabled()) {
            return $this->skip($analysis, 'ai_off');
        }

        $chat = BusinessChat::query()->find($analysis->business_chat_id);

        if (! $chat instanceof BusinessChat || ! $chat->isClient()) {
            return $this->skip($analysis, 'not_client');
        }

        $transcript = BusinessTranscript::build($chat);

        if ($transcript->isEmpty()) {
            return $this->skip($analysis, 'empty');
        }

        $profiles = AgentProfile::query()->with('currentVersion')->get()->keyBy(fn (AgentProfile $profile): string => $profile->role->value);
        $router = $profiles->get(AgentRole::Router->value)?->currentVersion;

        if (! $router instanceof AgentProfileVersion) {
            return $this->skip($analysis, 'no_profile');
        }

        $hash = $this->hash($transcript, $profiles->map(fn (AgentProfile $profile): ?int => $profile->current_version_id)->all());
        $last = $chat->analyses()->where('status', AnalysisStatus::Done->value)->whereKeyNot($analysis->id)->latest('id')->first();

        if ($last !== null && $last->input_hash === $hash) {
            return $this->skip($analysis, 'unchanged', ['input_hash' => $hash, 'up_to_message_id' => $transcript->upToMessageId]);
        }

        $previous = $chat->analyses()->where('status', AnalysisStatus::Done->value)->whereNotNull('summary')->whereKeyNot($analysis->id)->latest('id')->first();
        $previousSummary = EncryptedText::isReadable($previous?->summary) ? (string) $previous->summary : null;
        $output = AnalysisOutput::make();
        $tokens = ['input' => 0, 'output' => 0, 'cache' => 0];
        $models = [];

        // Router.
        $routed = $this->call($model, AiUsageChannel::OfficeRoute, OfficePrompt::router($router, $transcript, $previousSummary), $tokens, $models, 'router');

        if (is_string($routed)) {
            return $this->stop($analysis, $chat, $routed, ['router_version_id' => $router->id] + $this->usageColumns($tokens, $models));
        }

        $route = $output->router($routed->text);

        if ($route === null) {
            $this->finish($analysis, AnalysisStatus::Failed, ['error' => 'invalid_router', 'router_version_id' => $router->id] + $this->usageColumns($tokens, $models));

            return 'invalid_router';
        }

        $values = [
            'role' => $route['role'],
            'topic' => $route['topic'],
            'confidence' => $route['confidence'],
            'language' => $route['language'],
            'summary' => $route['summary'],
            'router_version_id' => $router->id,
            'input_hash' => $hash,
            'up_to_message_id' => $transcript->upToMessageId,
        ];

        // Role agent.
        $agentProfile = $route['role']->isAgent() ? $profiles->get($route['role']->value) : null;
        $agent = $agentProfile?->currentVersion;

        if ($agentProfile !== null && $agent instanceof AgentProfileVersion) {
            $site = $agentProfile->use_site_knowledge ? $this->siteBlock($transcript, $route['topic'], $route['language']) : '';
            $answered = $this->call($model, $route['role']->usageChannel(), OfficePrompt::agent($agent, $transcript, $previousSummary, $route['topic'], $site), $tokens, $models, 'agent');
            $values['agent_version_id'] = $agent->id;

            if (is_string($answered)) {
                return $this->stop($analysis, $chat, $answered, $values + $this->usageColumns($tokens, $models));
            }

            $result = $output->agent($answered->text);

            if ($result === null) {
                $this->finish($analysis, AnalysisStatus::Failed, ['error' => 'invalid_agent'] + $values + $this->usageColumns($tokens, $models));

                return 'invalid_agent';
            }

            $values = [
                ...$values,
                'summary' => $result['summary'] ?? $route['summary'],
                'next_step' => $result['next_step'],
                'draft_reply' => $result['draft'],
                'draft_blocked' => $result['draft_blocked'],
                'lead_fields' => $result['lead'],
            ];
        }

        return $this->save($analysis, $values + $this->usageColumns($tokens, $models));
    }

    /**
     * Store the result only while the chat is still a client chat (the lock is held, VO-12), then sync the lead.
     *
     * @param  array<string, mixed>  $values
     */
    protected function save(BusinessChatAnalysis $analysis, array $values): string
    {
        return DB::transaction(function () use ($analysis, $values): string {
            $chat = BusinessChat::query()->whereKey($analysis->business_chat_id)->lockForUpdate()->first();

            if (! $chat instanceof BusinessChat || ! $chat->isClient()) {
                $this->finish($analysis, AnalysisStatus::Skipped, ['error' => 'not_client']);

                return 'not_client';
            }

            $analysis->forceFill([...$values, 'status' => AnalysisStatus::Done, 'error' => null, 'finished_at' => now()])->save();

            $chat->last_analysed_message_id = $analysis->up_to_message_id;
            $chat->save();

            $lead = $this->syncLead->handle($chat, $analysis);
            $analysis->forceFill(['lead_action' => $lead['action'], 'lead_id' => $lead['lead_id']])->save();

            return 'done';
        });
    }

    /**
     * One model call with its reservation (VO-11): a result, or the outcome key (capped, failed …).
     *
     * @param  array{input: int, output: int, cache: int}  $tokens
     * @param  array<string, string>  $models
     */
    protected function call(ChatModel $model, ?AiUsageChannel $channel, AiRequest $request, array &$tokens, array &$models, string $stage): AiResult|string
    {
        return $this->modelCall->handle($model, $channel, $request, $tokens, $models, $stage);
    }

    /**
     * A call that did not answer: capped → due again tomorrow (Tashkent); anything else → failed.
     *
     * @param  array<string, mixed>  $values
     */
    protected function stop(BusinessChatAnalysis $analysis, BusinessChat $chat, string $outcome, array $values): string
    {
        if ($outcome === 'capped') {
            $this->finish($analysis, AnalysisStatus::Capped, ['error' => 'capped'] + $values);
            $chat->forceFill(['analysis_due_at' => now('Asia/Tashkent')->addDay()->startOfDay()->setTimezone(config('app.timezone'))])->save();

            return 'capped';
        }

        $this->finish($analysis, AnalysisStatus::Failed, ['error' => mb_substr($outcome, 0, 40)] + $values);

        return $outcome;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function skip(BusinessChatAnalysis $analysis, string $reason, array $values = []): string
    {
        $this->finish($analysis, AnalysisStatus::Skipped, ['error' => $reason] + $values);

        return $reason;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function finish(BusinessChatAnalysis $analysis, AnalysisStatus $status, array $values = []): void
    {
        $analysis->forceFill([...$values, 'status' => $status, 'finished_at' => now()])->save();
    }

    /**
     * @param  array<string, ?int>  $versionIds
     */
    protected function hash(BusinessTranscript $transcript, array $versionIds): string
    {
        ksort($versionIds);

        return hash('sha256', (string) json_encode([$transcript->stamps, $versionIds, CoreRules::VERSION]));
    }

    /**
     * Up to 5 public site passages for the client's words (titles and text, no URLs).
     */
    protected function siteBlock(BusinessTranscript $transcript, ?string $topic, ?string $language): string
    {
        $query = trim(($topic ?? '').' '.$transcript->clientText());

        if ($query === '') {
            return '';
        }

        $hits = array_slice($this->knowledge->handle($query, $language ?? (string) config('app.locale')), 0, 5);

        return implode("\n", array_map(fn (AiKnowledgeHit $hit): string => '- '.$hit->title.': '.$hit->text, $hits));
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
