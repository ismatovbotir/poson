<?php

namespace App\Livewire\Admin\Office\Chats;

use App\Actions\Office\AssignBusinessChat;
use App\Actions\Office\CreateLeadFromBusinessChat;
use App\Actions\Office\MarkBusinessChat;
use App\Actions\Office\OfficeStaff;
use App\Actions\Office\RequestBusinessChatAnalysis;
use App\Enums\AnalysisStatus;
use App\Enums\BusinessChatKind;
use App\Enums\LeadType;
use App\Models\BusinessChat;
use App\Models\BusinessChatAnalysis;
use App\Models\User;
use App\Support\Admin\PasswordConfirmation;
use App\Support\Office\OfficeSettings;
use App\Support\Office\PhoneSuggestion;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Virtual office → one business chat (/admin/office/chats/{id}, plan 41
 * §8.3, VO-8, VO-9): the read-only transcript (newest page, "load older"),
 * the latest analysis (role, topic, confidence, summary, next step, the
 * draft reply — copy only, never sent — and the lead block), the last 5
 * analyses with their version ids, "Analyse now", assignment and (Admin)
 * re-marking. The chat id is #[Locked]; mount and every method authorize
 * through BusinessChatPolicy on a fresh row. Polls every 15 s only while
 * an analysis is queued or running. Everything is rendered escaped, no
 * autolinks.
 */
#[Layout('layouts::admin')]
class Show extends Component
{
    public const MAX_MESSAGES = 1000;

    #[Locked]
    public int $chatId = 0;

    #[Locked]
    public int $limit = 50;

    public ?int $assignTarget = null;

    public string $markKind = '';

    public ?int $markAssignee = null;

    public string $leadPhone = '';

    public string $leadType = 'quote';

    public function mount(BusinessChat $businessChat): void
    {
        $this->authorize('view', $businessChat);

        $this->chatId = $businessChat->id;

        // Opening the chat marks its client messages read (counter only).
        if ($businessChat->unread_count > 0) {
            BusinessChat::query()->whereKey($businessChat->id)->update(['unread_count' => 0]);
        }

        $this->limit = (int) config('office.transcript_page', 50);
        $this->assignTarget = $businessChat->assignee_id;
        $this->leadType = $this->latest()?->role?->leadType() === LeadType::ServiceRequest ? LeadType::ServiceRequest->value : LeadType::Quote->value;
    }

    public function hydrate(): void
    {
        $this->authorize('view', $this->chat());
    }

    public function loadOlder(): void
    {
        $this->authorize('view', $this->chat());

        $this->limit = min(self::MAX_MESSAGES, $this->limit + (int) config('office.transcript_page', 50));
    }

    public function analyse(RequestBusinessChatAnalysis $request): void
    {
        $chat = $this->chat();
        $this->authorize('analyse', $chat);

        $request->handle($this->user(), $chat);

        $this->dispatch('toast', title: __('admin.toast.done'), body: __('office.show.analysis_queued'), type: 'success');
    }

    public function assign(AssignBusinessChat $assign): void
    {
        $chat = $this->chat();
        $this->authorize('assign', $chat);

        $assign->handle($this->user(), $chat, $this->assignTarget);

        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('office.show.assigned'), type: 'success');
        $this->chat(fresh: true);

        // A sales manager who handed the chat on can no longer see it.
        if (! $this->user()->can('view', $chat->fresh())) {
            $this->redirectRoute('admin.office.chats.index', navigate: true);
        }
    }

    public function remark(MarkBusinessChat $mark): void
    {
        $chat = $this->chat();
        $this->authorize('mark', $chat);

        $kind = BusinessChatKind::tryFrom($this->markKind);

        if ($kind === null || ! in_array($kind, BusinessChatKind::markable(), true)) {
            $this->addError('markKind', __('office.errors.kind_invalid'));

            return;
        }

        if ($kind !== BusinessChatKind::Client && ! PasswordConfirmation::isRecent()) {
            $this->redirect(PasswordConfirmation::url(route('admin.office.chats.show', $chat)), navigate: false);

            return;
        }

        $mark->handle($this->user(), $chat, $kind, $this->markAssignee, null, request()->ip());
        $this->chat(fresh: true);

        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('office.unmarked.marked', ['kind' => $kind->label()]), type: 'success');

        if ($kind !== BusinessChatKind::Client) {
            $this->redirectRoute('admin.office.chats.index', navigate: true);
        }
    }

    public function createLead(CreateLeadFromBusinessChat $create): void
    {
        $chat = $this->chat();
        $this->authorize('createLead', $chat);

        $this->validate([
            'leadPhone' => ['required', 'string', 'max:32'],
            'leadType' => ['required', 'in:'.LeadType::Quote->value.','.LeadType::ServiceRequest->value],
        ]);

        $lead = $create->handle($this->user(), $chat, $this->leadPhone, LeadType::from($this->leadType));

        $this->chat(fresh: true);

        $this->dispatch('toast', title: __('admin.toast.done'), body: __('office.show.lead_created', ['reference' => $lead->reference()]), type: 'success');
    }

    public function render(): View
    {
        $chat = $this->chat()->loadMissing(['assignee:id,name', 'lead:id,status,type', 'connection:id,is_enabled,can_reply']);
        $user = $this->user();

        $messages = $chat->messages()->orderByDesc('id')->limit($this->limit)->get()->reverse()->values();
        $analyses = $chat->analyses()->with(['agentVersion:id,version', 'routerVersion:id,version'])->latest('id')->limit(5)->get();
        $latest = $analyses->firstWhere('status', AnalysisStatus::Done);
        $pending = $analyses->contains(fn (BusinessChatAnalysis $analysis): bool => $analysis->status->isPending());
        $needsLead = $user->can('createLead', $chat) && ($chat->lead === null || ! $chat->lead->isOpen());

        if ($needsLead && $this->leadPhone === '') {
            $this->leadPhone = $chat->phone ?? (PhoneSuggestion::forChat($chat) ?? '');
        }

        return view('livewire.admin.office.chats.show', [
            'chat' => $chat,
            'messages' => $messages,
            'hasOlder' => $messages->count() >= $this->limit && $this->limit < self::MAX_MESSAGES,
            'analyses' => $analyses,
            'latest' => $latest,
            'pending' => $pending,
            'aiEnabled' => OfficeSettings::aiEnabled(),
            'canAnalyse' => $user->can('analyse', $chat),
            'canAssign' => $user->can('assign', $chat),
            'canMark' => $user->can('mark', $chat),
            'needsLead' => $needsLead,
            'assignable' => $user->can('assign', $chat) || $user->can('mark', $chat) ? OfficeStaff::assignable() : collect(),
        ])->title($chat->displayName());
    }

    /**
     * The chat, read once per request (protected: never part of the Livewire snapshot).
     */
    protected ?BusinessChat $loadedChat = null;

    private function chat(bool $fresh = false): BusinessChat
    {
        if ($fresh || $this->loadedChat === null) {
            $this->loadedChat = BusinessChat::query()->findOrFail($this->chatId);
        }

        return $this->loadedChat;
    }

    private function latest(): ?BusinessChatAnalysis
    {
        return BusinessChatAnalysis::query()->where('business_chat_id', $this->chatId)->where('status', AnalysisStatus::Done->value)->latest('id')->first();
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
