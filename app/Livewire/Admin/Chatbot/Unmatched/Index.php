<?php

namespace App\Livewire\Admin\Chatbot\Unmatched;

use App\Actions\Chat\AddKeywordFromUnmatched;
use App\Actions\Chat\ResolveUnmatched;
use App\Enums\ChatChannel;
use App\Enums\KeywordMatch;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Admin\Concerns\WithAdminPagination;
use App\Models\ChatRule;
use App\Models\ChatUnmatched;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Chat bot → Teach (plan 28 C2, chatbot.rules): phrases the bot did not
 * understand, most frequent first. Per phrase: add it as a keyword of a rule
 * (AddKeywordFromUnmatched), start a new rule with it, or ignore it. Phrases
 * are stored already redacted (CB-10) and shown as plain text.
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    use HandlesActionErrors, WithAdminPagination;

    public const PER_PAGE = 25;

    #[Url(except: 'open')]
    public string $show = 'open';

    #[Url(except: '')]
    public string $channel = '';

    /**
     * The row whose "add as keyword" form is open.
     */
    #[Locked]
    public int $teachingId = 0;

    public string $ruleId = '';

    public string $matchType = 'phrase';

    public int $weight = 10;

    public function mount(): void
    {
        $this->authorize('viewAny', ChatUnmatched::class);
    }

    public function hydrate(): void
    {
        $this->authorize('viewAny', ChatUnmatched::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['show', 'channel'], true)) {
            $this->resetPage();
            $this->closeTeach();
        }
    }

    public function openTeach(int $id): void
    {
        $this->authorize('viewAny', ChatUnmatched::class);

        $this->teachingId = $id;
        $this->reset('ruleId');
        $this->matchType = 'phrase';
        $this->weight = 10;
        $this->resetErrorBag();
    }

    public function closeTeach(): void
    {
        $this->teachingId = 0;
        $this->resetErrorBag();
    }

    public function addKeyword(AddKeywordFromUnmatched $addKeyword): void
    {
        $unmatched = ChatUnmatched::query()->findOrFail($this->teachingId);
        $this->authorize('update', $unmatched);

        $rule = ctype_digit($this->ruleId) ? ChatRule::query()->find((int) $this->ruleId) : null;
        $type = KeywordMatch::tryFrom($this->matchType);

        if ($rule === null || $type === null) {
            $this->addError('ruleId', __('chatbot_admin.unmatched.choose_rule'));

            return;
        }

        $this->authorize('update', $rule);

        $done = $this->runToastAction(function () use ($addKeyword, $unmatched, $rule, $type): void {
            $addKeyword->handle($unmatched, $rule, auth()->user(), $type, $this->weight);
        });

        if ($done) {
            $this->closeTeach();
            $this->dispatch('toast', title: __('admin.toast.saved'), body: __('chatbot_admin.unmatched.taught', ['rule' => $rule->name]));
        }
    }

    public function ignore(int $id, ResolveUnmatched $resolveUnmatched): void
    {
        $unmatched = ChatUnmatched::query()->findOrFail($id);
        $this->authorize('update', $unmatched);

        if ($resolveUnmatched->handle($unmatched, auth()->user())) {
            $this->dispatch('toast', title: __('admin.toast.done'), body: __('chatbot_admin.unmatched.ignored'));
        }
    }

    /**
     * Rules the phrase can be added to (id => name).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function ruleOptions(): array
    {
        return ChatRule::query()->orderBy('name')->limit((int) config('chatbot.max_rules', 500))->pluck('name', 'id')->all();
    }

    public function render(): View
    {
        $this->authorize('viewAny', ChatUnmatched::class);

        $items = ChatUnmatched::query()
            ->with('resolvedRule:id,name')
            ->when($this->show === 'resolved', fn (Builder $query) => $query->whereNotNull('resolved_at'), fn (Builder $query) => $query->whereNull('resolved_at'))
            ->when(ChatChannel::tryFrom($this->channel), fn (Builder $query, ChatChannel $channel) => $query->where('channel', $channel->value))
            ->orderByDesc('count')
            ->orderByDesc('last_seen_at')
            ->paginate(self::PER_PAGE);

        return view('livewire.admin.chatbot.unmatched.index', [
            'items' => $items,
        ])->title(__('chatbot_admin.unmatched.title'));
    }
}
