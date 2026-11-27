<?php

namespace App\Actions\Office;

use App\Enums\AgentRole;
use App\Enums\AiUsageChannel;
use App\Enums\Role;
use App\Models\AgentProfile;
use App\Models\AgentProfileVersion;
use App\Models\BusinessChat;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiResult;
use App\Services\Ai\ChatModel;
use App\Support\Office\AnalysisOutput;
use App\Support\Office\BusinessTranscript;
use App\Support\Office\GroupPrompt;
use App\Support\Office\GroupTranscript;
use App\Support\Office\OfficePrompt;
use App\Support\Office\OfficeSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The dry-run test console (plan 46 §2.3, ED-4, ED46-6, ED46-7): try the published version (A) and a saved
 * version (B) of one agent on a real, redacted conversation WITHOUT saving anything.
 *
 *  - Admin only (office.education and the Admin role, here and in the component) and only while the office AI
 *    is on (key present, not paused).
 *  - The transcript is rebuilt for every step through BusinessTranscript / GroupTranscript (redacted) and goes
 *    only into the request; it is never stored, shown, logged or put into a snapshot.
 *  - The run lives in a server-side cache record (30 minutes): user, role, source, version ids, the steps
 *    done and their filtered results. The component only keeps its #[Locked] uuid. A forged, expired or
 *    foreign uuid is refused. A step needs the one before it, claims its slot atomically (Cache::add: a
 *    replay is refused) and takes the run's flight lock (a step in flight refuses the next one).
 *  - Limits live here (counted atomically, a refused step 1 gives its run back): 3 runs per user and hour, global run and call limits; every call spends on vo_dry
 *    through CallOfficeModel (reserve before, settle in finally, caps in Office → Settings); one call of at
 *    most education.dry_timeout seconds, so a step stays well under 60 s.
 *  - Outputs pass the same AnalysisOutput checks as a real analysis. A blocked or not-stored draft shows only
 *    its reason. The only writes: usage counters, rate-limiter counters and the cache record. No analysis,
 *    thread, lead, activity, feedback or Telegram call.
 * Steps: an agent role: 1 router (published), 2 agent A, 3 agent B; the router role: 1 router A, 2 router B.
 */
class RunEducationDryRun
{
    public function __construct(protected CallOfficeModel $call) {}

    /**
     * Start a run: validate, count it, create the record and run step 1.
     *
     * @return array{run: string, result: array<string, mixed>}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function start(User $actor, AgentRole $role, string $sourceType, int $sourceId, int $versionBId): array
    {
        $this->gate($actor);

        $profile = in_array($role, AgentRole::profiles(), true) ? AgentProfile::forRole($role)?->load('currentVersion') : null;
        $a = $profile?->currentVersion;
        $b = $profile?->versions()->whereKey($versionBId)->first();

        if (! $profile instanceof AgentProfile || ! $a instanceof AgentProfileVersion || ! $b instanceof AgentProfileVersion) {
            throw $this->refuse('version');
        }

        if ($this->source($sourceType, $sourceId) === null) {
            throw $this->refuse('source');
        }

        // Counted atomically (increment first, compare the returned count) and kept only once step 1 is accepted:
        // a step 1 that is refused (busy, throttled, ai_off) gives the run back.
        $keys = ['office-dry-runs:'.$actor->id => (int) config('office.education.dry_runs_per_hour', 3), 'office-dry-runs' => (int) config('office.education.dry_runs_global_per_hour', 6)];
        $this->take($keys);

        $run = (string) Str::uuid();
        $this->put($run, ['user' => $actor->id, 'role' => $role->value, 'source_type' => $sourceType, 'source_id' => $sourceId, 'a' => $a->id, 'b' => $b->id, 'steps' => [], 'plan' => null]);

        try {
            return ['run' => $run, 'result' => $this->step($actor, $run, 1)];
        } catch (\Throwable $refused) {
            Cache::forget('office-dry:'.$run);
            $this->give(array_keys($keys));

            throw $refused;
        }
    }

    /**
     * Run one step of a run.
     *
     * @return array<string, mixed> The filtered result of the step
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function step(User $actor, string $run, int $step): array
    {
        $this->gate($actor);

        $record = $this->record($actor, $run);
        $role = AgentRole::from($record['role']);
        $last = $role === AgentRole::Router ? 2 : 3;

        if ($step < 1 || $step > $last || ($step > 1 && ($record['steps'][$step - 1]['outcome'] ?? null) !== 'ok')) {
            throw $this->refuse('order');
        }

        $flight = Cache::lock('office-dry-flight:'.$run, 60);

        if (! $flight->get()) {
            throw $this->refuse('busy');
        }

        try {
            // A fresh read under the lock, then the slot: a replay of the same step is refused.
            $record = $this->record($actor, $run);

            if (! Cache::add('office-dry-step:'.$run.':'.$step, 1, now()->addMinutes($this->ttl()))) {
                throw $this->refuse('replayed');
            }

            try {
                $this->take(['office-dry-calls' => (int) config('office.education.dry_calls_global_per_hour', 18)]);
            } catch (ValidationException $throttled) {
                Cache::forget('office-dry-step:'.$run.':'.$step);

                throw $throttled;
            }

            try {
                $result = $this->execute($role, $record, $step);
            } catch (\Throwable $failure) {
                // The step can be retried; the call counter keeps the hit (a call may have gone out).
                Cache::forget('office-dry-step:'.$run.':'.$step);

                throw $failure;
            }

            $record['steps'][$step] = $result['result'];
            $record['plan'] = $result['plan'] ?? $record['plan'];

            // After the last step the results stay readable for a few minutes only; a failed or capped step ends the run too.
            $this->put($run, $record, $step === $last || ($result['result']['outcome'] ?? 'ok') !== 'ok' ? 5 : null);

            return $result['result'];
        } finally {
            $flight->release();
        }
    }

    /**
     * The run record of this user, or null (expired, forged, somebody else's). Filtered results only.
     *
     * @return array<string, mixed>|null
     */
    public function find(User $actor, ?string $run): ?array
    {
        $record = $run === null ? null : Cache::get('office-dry:'.$run);

        return is_array($record) && ($record['user'] ?? null) === $actor->id ? $record : null;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{result: array<string, mixed>, plan?: array<string, mixed>|null}
     */
    protected function execute(AgentRole $role, array $record, int $step): array
    {
        $isGroup = $record['source_type'] === 'group';
        $source = $this->source($record['source_type'], (int) $record['source_id']);

        if ($source === null) {
            return ['result' => $this->failure($step, 'source')];
        }

        $transcript = $isGroup ? GroupTranscript::build($source) : BusinessTranscript::build($source);

        if ($transcript->isEmpty()) {
            return ['result' => $this->failure($step, 'empty')];
        }

        $profile = AgentProfile::forRole($role);
        $routerStep = $role === AgentRole::Router || $step === 1;
        $version = $routerStep
            ? ($role === AgentRole::Router ? $profile?->versions()->whereKey($step === 1 ? $record['a'] : $record['b'])->first() : AgentProfile::forRole(AgentRole::Router)?->currentVersion)
            : $profile?->versions()->whereKey($step === 2 ? $record['a'] : $record['b'])->first();

        if (! $version instanceof AgentProfileVersion) {
            return ['result' => $this->failure($step, 'version')];
        }

        $plan = (array) ($record['plan'] ?? []);

        if (! $routerStep && ($plan['no_thread'] ?? false)) {
            return ['result' => $this->failure($step, 'no_thread')];
        }

        $request = $routerStep
            ? ($isGroup ? GroupPrompt::router($version, $transcript) : OfficePrompt::router($version, $transcript, null))
            : ($isGroup
                ? GroupPrompt::agent($version, $transcript, null, $plan['topic'] ?? null, (array) ($plan['labels'] ?? []), false, $role)
                : OfficePrompt::agent($version, $transcript, null, $plan['topic'] ?? null, '', $role));

        $tokens = ['input' => 0, 'output' => 0, 'cache' => 0];
        $models = [];
        $answer = $this->call->handle(app(ChatModel::class), AiUsageChannel::OfficeDry, $this->shortTimeout($request), $tokens, $models, 'dry'.$step);

        if (is_string($answer)) {
            return ['result' => $this->failure($step, $answer, $version)];
        }

        $label = $routerStep ? ($role === AgentRole::Router ? ($step === 1 ? 'A' : 'B') : 'router') : ($step === 2 ? 'A' : 'B');
        $output = AnalysisOutput::make();

        if ($routerStep) {
            $route = $isGroup ? $this->groupRoute($output, $answer, $transcript, $role) : $output->router($answer->text);

            if ($route === null) {
                return ['result' => $this->failure($step, 'invalid', $version)];
            }

            $result = ['step' => $step, 'label' => $label, 'kind' => 'router', 'outcome' => 'ok', 'version' => $version->version, 'role' => $route['role']->value, 'topic' => $route['topic'], 'confidence' => $route['confidence'], 'summary' => $route['summary'], 'tokens' => $answer->inputTokens + $answer->outputTokens];

            return ['result' => $result, 'plan' => $role === AgentRole::Router ? null : $this->plan($route, $isGroup, $role)];
        }

        $guidance = $version->guidanceFor($role);
        $clientText = $isGroup ? $transcript->clientText((array) ($plan['member_ids'] ?? [])) : $transcript->clientTextSince(null);
        $agent = $output->agent($answer->text, $guidance, ['client_text' => $clientText, 'confidence' => $plan['confidence'] ?? null, 'language' => $plan['language'] ?? null]);

        if ($agent === null) {
            return ['result' => $this->failure($step, 'invalid', $version)];
        }

        // A draft that is blocked, held for escalation / forbidden / SLA is not stored by the checks: only its reason is shown.
        return ['result' => [
            'step' => $step, 'label' => $label, 'kind' => 'agent', 'outcome' => 'ok', 'version' => $version->version, 'role' => $role->value,
            'topic' => $plan['topic'] ?? null, 'confidence' => $plan['confidence'] ?? null,
            'summary' => $agent['summary'], 'next_step' => $agent['next_step'], 'draft' => $agent['draft'],
            'blocked' => $agent['draft_blocked'], 'hold' => $agent['draft_hold']?->value,
            'needs_human' => $agent['needs_human'], 'escalation' => $agent['escalation'], 'urgency' => $agent['lead']['urgency'],
            'tokens' => $answer->inputTokens + $answer->outputTokens,
        ]];
    }

    /**
     * What the agent steps need from the router: the topic, confidence, language and (a group) the first thread of the role.
     *
     * @param  array{role: AgentRole, topic: ?string, confidence: int, language: ?string, summary: ?string, labels?: list<string>, member_ids?: list<int>}  $route
     * @return array<string, mixed>
     */
    protected function plan(array $route, bool $isGroup, AgentRole $role): array
    {
        $plan = ['topic' => $route['topic'], 'confidence' => $route['confidence'], 'language' => $route['language']];

        if ($isGroup) {
            $plan['labels'] = $route['labels'] ?? [];
            $plan['member_ids'] = $route['member_ids'] ?? [];
            $plan['no_thread'] = ! isset($route['labels']) || $route['labels'] === [];
        }

        return $plan;
    }

    /**
     * A group router answer: the first valid thread of the chosen role (labels of this run only), else the first thread.
     *
     * @return array{role: AgentRole, topic: ?string, confidence: int, language: ?string, needs_reply: bool, summary: ?string, labels?: list<string>, member_ids?: list<int>}|null
     */
    protected function groupRoute(AnalysisOutput $output, AiResult $answer, GroupTranscript $transcript, AgentRole $role): ?array
    {
        $data = AnalysisOutput::decode($answer->text);
        $language = is_string($data['language'] ?? null) && in_array($data['language'], OfficePrompt::LANGUAGES, true) ? $data['language'] : null;
        $first = null;

        foreach (array_slice(is_array($data['threads'] ?? null) ? $data['threads'] : [], 0, GroupPrompt::MAX_THREADS) as $thread) {
            $valid = is_array($thread) ? $output->routerData([...$thread, 'language' => $language, 'needs_reply' => true]) : null;

            if ($valid === null) {
                continue;
            }

            $labels = array_values(array_filter(array_map('strval', (array) ($thread['client_labels'] ?? [])), fn (string $label): bool => $transcript->clientMemberId($label) !== null));
            $memberIds = array_values(array_unique(array_filter(array_map(fn (string $label): ?int => $transcript->clientMemberId($label), $labels))));
            $row = $valid + ['labels' => $labels, 'member_ids' => $memberIds];
            $first ??= $row;

            if ($valid['role'] === $role && $labels !== []) {
                return $row;
            }
        }

        // No thread of the chosen role: the console says so instead of guessing.
        return $first === null ? ($data !== null ? ['role' => AgentRole::Other, 'topic' => null, 'confidence' => 0, 'language' => $language, 'needs_reply' => false, 'summary' => null] : null) : [...$first, 'labels' => []];
    }

    /**
     * The same request with a short, bounded timeout (a step stays under 60 s).
     */
    protected function shortTimeout(AiRequest $request): AiRequest
    {
        $timeout = min(25.0, (float) config('office.education.dry_timeout', 25));

        return new AiRequest($request->system, $request->messages, $request->maxTokens, $request->model, $request->effort, min($timeout, $request->timeout ?? $timeout), $request->images, $request->outputSchema);
    }

    /**
     * @return array<string, mixed>
     */
    protected function failure(int $step, string $outcome, ?AgentProfileVersion $version = null): array
    {
        return ['step' => $step, 'outcome' => $outcome, 'version' => $version?->version];
    }

    protected function source(string $type, int $id): BusinessChat|TelegramGroup|null
    {
        if ($type === 'chat') {
            $chat = BusinessChat::query()->find($id);

            return $chat instanceof BusinessChat && $chat->isClient() ? $chat : null;
        }

        if ($type === 'group') {
            $group = TelegramGroup::query()->find($id);

            return $group instanceof TelegramGroup && $group->isTracked() ? $group : null;
        }

        return null;
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    protected function gate(User $actor): void
    {
        if (! $actor->hasRole(Role::Admin->value) || ! $actor->can('office.education')) {
            throw new AuthorizationException;
        }

        if (! OfficeSettings::aiEnabled()) {
            throw $this->refuse('ai_off');
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    protected function record(User $actor, string $run): array
    {
        return $this->find($actor, $run) ?? throw $this->refuse('expired');
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function put(string $run, array $record, ?int $minutes = null): void
    {
        Cache::put('office-dry:'.$run, $record, now()->addMinutes($minutes ?? $this->ttl()));
    }

    /**
     * Count one attempt on each limiter, atomically: the incremented count is compared, and a refused request
     * gives its increments back.
     *
     * @param  array<string, int>  $limits  key => attempts per hour
     *
     * @throws ValidationException
     */
    protected function take(array $limits): void
    {
        $taken = [];

        foreach ($limits as $key => $max) {
            $taken[] = $key;

            if (RateLimiter::hit($key, 3600) > $max) {
                $this->give($taken);

                throw $this->refuse('throttled');
            }
        }
    }

    /**
     * @param  list<string>  $keys
     */
    protected function give(array $keys): void
    {
        foreach ($keys as $key) {
            RateLimiter::decrement($key, 3600);
        }
    }

    protected function ttl(): int
    {
        return max(1, (int) config('office.education.dry_ttl_minutes', 30));
    }

    protected function refuse(string $reason): ValidationException
    {
        return ValidationException::withMessages(['dry' => __('office.dry.errors.'.$reason)]);
    }
}
