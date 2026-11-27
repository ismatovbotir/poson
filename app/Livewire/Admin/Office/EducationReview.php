<?php

namespace App\Livewire\Admin\Office;

use App\Actions\Office\PromoteFeedbackToExample;
use App\Enums\AgentRole;
use App\Enums\DraftFeedbackReason;
use App\Enums\DraftOutcome;
use App\Enums\Role;
use App\Models\AgentProfileVersion;
use App\Models\BusinessChatAnalysis;
use App\Models\OfficeDraftFeedback;
use App\Models\TelegramGroupAnalysis;
use App\Models\TelegramGroupThread;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Virtual office → Education → Review (/admin/office/education/review, plan 46 §2.2, ED-1, ED-5): what staff did
 * with the AI drafts. Admin only (office.education AND the Admin role). Tabs: Feedback (edited / rejected,
 * latest 50, filters; the encrypted note, draft and final text are opened on demand), Attention (last 7 days:
 * needs a human, held, blocked, "other") and Metrics (counts and sums only, never an average, never a per-text
 * figure). Promote turns a row into an example of a NEW unpublished version (PromoteFeedbackToExample). The
 * opened and the promoted row ids are #[Locked] and set only by methods that re-query the row.
 */
#[Layout('layouts::admin')]
class EducationReview extends Component
{
    public const LIMIT = 50;

    public string $tab = 'feedback';

    public string $role = '';

    public string $outcome = '';

    public string $reason = '';

    public string $period = '30';

    #[Locked]
    public ?int $openId = null;

    #[Locked]
    public ?int $promoteId = null;

    public string $kind = 'good';

    public string $language = 'uz';

    public string $client = '';

    public string $reply = '';

    public string $note = '';

    public bool $anonymised = false;

    public string $password = '';

    public function mount(): void
    {
        $this->guard();
    }

    public function hydrate(): void
    {
        $this->guard();
    }

    /**
     * A filter change never keeps a typed password in the snapshot. (Not a generic updated(): the deferred form
     * fields and the password arrive together with promote().)
     */
    public function updatedRole(): void
    {
        $this->password = '';
    }

    public function updatedOutcome(): void
    {
        $this->password = '';
    }

    public function updatedReason(): void
    {
        $this->password = '';
    }

    public function updatedPeriod(): void
    {
        $this->password = '';
    }

    public function setTab(string $tab): void
    {
        $this->guard();
        $this->password = '';

        if (in_array($tab, ['feedback', 'attention', 'metrics'], true)) {
            $this->tab = $tab;
        }
    }

    /**
     * Show the decrypted texts of one row (opened on demand).
     */
    public function open(int $id): void
    {
        $this->guard();
        $this->password = '';

        $this->openId = OfficeDraftFeedback::query()->whereKey($id)->exists() ? $id : null;
    }

    public function close(): void
    {
        $this->guard();

        $this->reset('openId', 'promoteId', 'client', 'reply', 'note', 'anonymised', 'password');
    }

    /**
     * Start Promote: the reply is pre-filled from the redacted final text (the draft for "used"); the
     * client side is written by the Admin, never copied from the transcript.
     */
    public function startPromote(int $id): void
    {
        $this->guard();
        $this->password = '';

        $row = OfficeDraftFeedback::query()->whereKey($id)->whereNull('promoted_version_id')->first();

        if (! $row instanceof OfficeDraftFeedback || ! in_array($row->role, AgentRole::agents(), true)) {
            $this->addError('feedback', __('office.review.errors.gone'));

            return;
        }

        $this->promoteId = $row->id;
        $this->kind = $row->outcome === DraftOutcome::Rejected ? 'bad' : 'good';
        $this->language = 'uz';
        $this->client = '';
        $this->reply = (string) ($row->outcome === DraftOutcome::Rejected ? $row->draft_text : ($row->final_text ?? $row->draft_text));
        $this->note = '';
        $this->anonymised = false;
        $this->password = '';
        $this->resetErrorBag();
    }

    public function promote(PromoteFeedbackToExample $promote): void
    {
        $this->guard();

        $row = $this->promoteId === null ? null : OfficeDraftFeedback::query()->find($this->promoteId);

        if (! $row instanceof OfficeDraftFeedback) {
            $this->password = '';
            $this->addError('feedback', __('office.review.errors.gone'));

            return;
        }

        try {
            $version = $promote->handle($this->user(), $row, [
                'kind' => $this->kind, 'language' => $this->language, 'client' => $this->client, 'reply' => $this->reply,
                'note' => $this->note, 'anonymised' => $this->anonymised, 'password' => $this->password,
            ], request()->ip());
        } finally {
            // The password never stays in the component state.
            $this->password = '';
        }

        $this->reset('promoteId', 'client', 'reply', 'note', 'anonymised');
        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('office.review.promoted', ['version' => $version->version]), type: 'success');
    }

    public function render(): View
    {
        $this->guard();

        return view('livewire.admin.office.education-review', [
            'rows' => $this->tab === 'feedback' ? $this->feedbackRows : collect(),
            'attention' => $this->tab === 'attention' ? $this->attentionItems : [],
            'metrics' => $this->tab === 'metrics' ? $this->metricsData : [],
            'roles' => AgentRole::agents(),
            'outcomes' => DraftOutcome::cases(),
            'reasons' => DraftFeedbackReason::cases(),
            'open' => $this->openId === null ? null : OfficeDraftFeedback::query()->find($this->openId),
            'promoting' => $this->promoteId === null ? null : OfficeDraftFeedback::query()->find($this->promoteId),
        ])->title(__('office.review.title'));
    }

    /**
     * Edited and rejected rows (all outcomes when one is chosen), newest first, with the filters.
     *
     * @return Collection<int, OfficeDraftFeedback>
     */
    #[Computed]
    public function feedbackRows(): Collection
    {
        return $this->filtered(OfficeDraftFeedback::query())
            ->when($this->outcome === '', fn ($query) => $query->whereIn('outcome', [DraftOutcome::Edited->value, DraftOutcome::Rejected->value]))
            ->with(['agentVersion:id,version', 'user:id,name'])
            ->latest('id')->limit(self::LIMIT)->get();
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Builder
     *
     * @param  T  $query
     * @return T
     */
    private function filtered($query)
    {
        return $query
            ->when(AgentRole::tryFrom($this->role) !== null, fn ($q) => $q->where('role', $this->role))
            ->when(DraftOutcome::tryFrom($this->outcome) !== null, fn ($q) => $q->where('outcome', $this->outcome))
            ->when(DraftFeedbackReason::tryFrom($this->reason) !== null, fn ($q) => $q->where('reason', $this->reason))
            ->when(in_array($this->period, ['7', '30', '90'], true), fn ($q) => $q->where('created_at', '>=', now()->subDays((int) $this->period)));
    }

    /**
     * The last 7 days: chats and group threads that need a person, held or blocked drafts, and "other" routes.
     *
     * @return array{chats: Collection<int, BusinessChatAnalysis>, threads: Collection<int, TelegramGroupThread>}
     */
    #[Computed]
    public function attentionItems(): array
    {
        $since = now()->subDays(7);

        return [
            'chats' => BusinessChatAnalysis::query()->where('status', 'done')->where('created_at', '>=', $since)
                ->where(fn ($q) => $q->where('needs_human', true)->orWhereNotNull('draft_hold')->orWhere('draft_blocked', true)->orWhere('role', AgentRole::Other->value))
                ->latest('id')->limit(30)->get(['id', 'business_chat_id', 'role', 'needs_human', 'escalation', 'draft_hold', 'draft_blocked', 'created_at']),
            'threads' => TelegramGroupThread::query()->where('updated_at', '>=', $since)
                ->where(fn ($q) => $q->where('needs_human', true)->orWhereNotNull('draft_hold')->orWhere('draft_blocked', true))
                ->latest('id')->limit(30)->get(['id', 'telegram_group_id', 'role', 'needs_human', 'escalation', 'draft_hold', 'draft_blocked', 'updated_at']),
        ];
    }

    /**
     * Counts and sums only, grouped in the database: feedback per outcome and reason, per role and per version,
     * and per version the analyses, blocked, held and needs-human counts and the token sums. No average,
     * no per-text figure (ED-5).
     *
     * @return array{outcomes: array<string, int>, reasons: array<string, int>, byRole: array<string, array<string, int>>, versions: list<array<string, mixed>>}
     */
    #[Computed]
    public function metricsData(): array
    {
        $feedback = fn () => $this->filtered(OfficeDraftFeedback::query());
        $since = in_array($this->period, ['7', '30', '90'], true) ? now()->subDays((int) $this->period) : null;

        $byRole = [];

        foreach ($feedback()->select('role', 'outcome', DB::raw('count(*) as total'))->groupBy('role', 'outcome')->get() as $row) {
            $byRole[(string) $row->getRawOriginal('role')][(string) $row->getRawOriginal('outcome')] = (int) $row->getAttribute('total');
        }

        $versions = [];
        $scope = fn ($query) => $since === null ? $query : $query->where('created_at', '>=', $since);

        foreach ($scope(BusinessChatAnalysis::query())->where('status', 'done')->whereNotNull('agent_version_id')
            ->selectRaw('agent_version_id, count(*) as analyses, sum(draft_blocked) as blocked, sum(case when draft_hold is not null then 1 else 0 end) as held, sum(needs_human) as needs_human, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens')
            ->groupBy('agent_version_id')->get() as $row) {
            $versions[(int) $row->agent_version_id] = ['analyses' => (int) $row->analyses, 'blocked' => (int) $row->blocked, 'held' => (int) $row->held, 'needs_human' => (int) $row->needs_human, 'input_tokens' => (int) $row->input_tokens, 'output_tokens' => (int) $row->output_tokens];
        }

        foreach ($scope(TelegramGroupAnalysis::query())->where('status', 'done')->whereNotNull('agent_version_id')
            ->selectRaw('agent_version_id, count(*) as analyses, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens')
            ->groupBy('agent_version_id')->get() as $row) {
            $current = $versions[(int) $row->agent_version_id] ?? ['analyses' => 0, 'blocked' => 0, 'held' => 0, 'needs_human' => 0, 'input_tokens' => 0, 'output_tokens' => 0];
            $versions[(int) $row->agent_version_id] = [...$current, 'analyses' => $current['analyses'] + (int) $row->analyses, 'input_tokens' => $current['input_tokens'] + (int) $row->input_tokens, 'output_tokens' => $current['output_tokens'] + (int) $row->output_tokens];
        }

        $counts = [];

        foreach ($feedback()->whereNotNull('agent_version_id')->select('agent_version_id', 'outcome', DB::raw('count(*) as total'))->groupBy('agent_version_id', 'outcome')->get() as $row) {
            $counts[(int) $row->agent_version_id][(string) $row->getRawOriginal('outcome')] = (int) $row->getAttribute('total');
        }

        $ids = array_values(array_unique([...array_keys($versions), ...array_keys($counts)]));
        $labels = AgentProfileVersion::query()->whereKey($ids)->with('profile:id,role')->get(['id', 'agent_profile_id', 'version'])->keyBy('id');
        $rows = [];

        foreach ($ids as $id) {
            $rows[] = [
                'role' => $labels->get($id)?->profile?->role,
                'version' => $labels->get($id)?->version,
                'used' => $counts[$id]['used'] ?? 0,
                'edited' => $counts[$id]['edited'] ?? 0,
                'rejected' => $counts[$id]['rejected'] ?? 0,
            ] + ($versions[$id] ?? ['analyses' => 0, 'blocked' => 0, 'held' => 0, 'needs_human' => 0, 'input_tokens' => 0, 'output_tokens' => 0]);
        }

        usort($rows, fn (array $a, array $b): int => [(string) $a['role']?->value, -(int) $a['version']] <=> [(string) $b['role']?->value, -(int) $b['version']]);

        return [
            'outcomes' => $feedback()->select('outcome', DB::raw('count(*) as total'))->groupBy('outcome')->get()->mapWithKeys(fn ($row) => [(string) $row->getRawOriginal('outcome') => (int) $row->getAttribute('total')])->all(),
            'reasons' => $feedback()->whereNotNull('reason')->select('reason', DB::raw('count(*) as total'))->groupBy('reason')->get()->mapWithKeys(fn ($row) => [(string) $row->getRawOriginal('reason') => (int) $row->getAttribute('total')])->all(),
            'byRole' => $byRole,
            'versions' => $rows,
        ];
    }

    /**
     * Admin only: the permission and the role itself (ED-1), on every request.
     */
    private function guard(): void
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->can('office.education') || ! $user->hasRole(Role::Admin->value)) {
            throw new AuthorizationException;
        }
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
