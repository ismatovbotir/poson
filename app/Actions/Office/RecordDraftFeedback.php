<?php

namespace App\Actions\Office;

use App\Casts\EncryptedText;
use App\Enums\AnalysisStatus;
use App\Enums\DraftFeedbackReason;
use App\Enums\DraftOutcome;
use App\Models\BusinessChat;
use App\Models\BusinessChatAnalysis;
use App\Models\OfficeDraftFeedback;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupAnalysis;
use App\Models\TelegramGroupThread;
use App\Models\User;
use App\Support\Chat\ChatRedactor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What staff did with an AI draft: used, edited or rejected (plan 46 §2.2, ED-3, ED46-8).
 *
 *  - Allowed with office.analyse and BusinessChatPolicy::view / TelegramGroupPolicy::view (visibleTo);
 *    the chat / group is re-queried here from the id the screen kept (#[Locked]) and every foreign key
 *    is derived on the server, never taken from the input. The visibility is checked now, at submit.
 *  - Only for the latest done analysis (chat) or the thread's latest analysis (group) whose draft is
 *    stored, readable and not blocked: a stale or tampered id is refused.
 *  - outcome used / edited / rejected; reason (required unless used); note up to 500 and final_text up to
 *    1500 characters, both through ChatRedactor::clean (links, contacts, cards and digit runs masked) and
 *    stored encrypted (EncryptedText) with an encrypted draft snapshot, the agent version, the role and the
 *    user. One row per draft (upsert, last writer wins).
 *  - Throttled to 30 submissions per user and hour inside this method (a route throttle does not cover
 *    /livewire/update).
 * The texts are nulled by ForgetDraftFeedback at every site that nulls drafts and by the retention.
 */
class RecordDraftFeedback
{
    public const PER_HOUR = 30;

    /**
     * @param  BusinessChat|TelegramGroup  $target  Only its id is used: the row is re-read here
     * @param  array{outcome?: mixed, reason?: mixed, note?: mixed, final_text?: mixed, analysis_id?: mixed, thread_id?: mixed}  $data  analysis_id (chat) / thread_id (group): the draft the screen showed
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, BusinessChat|TelegramGroup $target, array $data): OfficeDraftFeedback
    {
        $fresh = $target instanceof BusinessChat ? BusinessChat::query()->find($target->id) : TelegramGroup::query()->find($target->id);

        if ($fresh === null || ! $actor->can('office.analyse') || ! Gate::forUser($actor)->allows('view', $fresh)) {
            throw new AuthorizationException;
        }

        $key = 'office-draft-feedback:'.$actor->id;

        if (RateLimiter::tooManyAttempts($key, self::PER_HOUR)) {
            throw ValidationException::withMessages(['feedback' => __('office.feedback.throttled')]);
        }

        RateLimiter::hit($key, 3600);

        $valid = Validator::make($data, [
            'outcome' => ['required', Rule::enum(DraftOutcome::class)],
            'reason' => [Rule::requiredIf(fn (): bool => ($data['outcome'] ?? null) !== DraftOutcome::Used->value), 'nullable', Rule::enum(DraftFeedbackReason::class)],
            'note' => ['nullable', 'string', 'max:500'],
            'final_text' => [Rule::requiredIf(fn (): bool => ($data['outcome'] ?? null) === DraftOutcome::Edited->value), 'nullable', 'string', 'max:1500'],
        ])->validate();

        $outcome = DraftOutcome::from($valid['outcome']);
        $values = [
            'outcome' => $outcome,
            'reason' => $outcome === DraftOutcome::Used ? null : DraftFeedbackReason::tryFrom((string) $valid['reason']),
            'note' => $this->clean($valid['note'] ?? null, 500),
            'final_text' => $outcome === DraftOutcome::Used ? null : $this->clean($valid['final_text'] ?? null, 1500),
            'user_id' => $actor->id,
        ];

        if ($outcome === DraftOutcome::Edited && $values['final_text'] === null) {
            throw ValidationException::withMessages(['final_text' => __('validation.required', ['attribute' => 'final_text'])]);
        }

        return $fresh instanceof BusinessChat
            ? $this->forChat($fresh, $values, $data['analysis_id'] ?? null)
            : $this->forThread($fresh, $values, $data['thread_id'] ?? null, $data['analysis_id'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function forChat(BusinessChat $chat, array $values, mixed $analysisId): OfficeDraftFeedback
    {
        $latest = $chat->analyses()->where('status', AnalysisStatus::Done->value)->latest('id')->first();

        if (! $latest instanceof BusinessChatAnalysis || (int) $analysisId !== $latest->id || $latest->draft_blocked || ! EncryptedText::isReadable($latest->draft_reply)) {
            throw ValidationException::withMessages(['feedback' => __('office.feedback.stale')]);
        }

        return $this->upsert(
            ['business_chat_analysis_id' => $latest->id],
            $values + [
                'business_chat_id' => $chat->id,
                'telegram_group_id' => null,
                'agent_version_id' => $latest->agent_version_id,
                'role' => $latest->role,
                'draft_text' => mb_substr((string) $latest->draft_reply, 0, 1500),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function forThread(TelegramGroup $group, array $values, mixed $threadId, mixed $analysisId): OfficeDraftFeedback
    {
        $thread = TelegramGroupThread::query()->where('telegram_group_id', $group->id)->whereKey((int) $threadId)->first();
        $analysis = $thread?->last_analysis_id === null ? null : TelegramGroupAnalysis::query()->where('telegram_group_id', $group->id)->whereKey($thread->last_analysis_id)->first();

        if (! $thread instanceof TelegramGroupThread || ! $analysis instanceof TelegramGroupAnalysis || $analysis->status !== AnalysisStatus::Done
            || (int) $analysisId !== $analysis->id || $thread->draft_blocked || ! EncryptedText::isReadable($thread->draft)) {
            throw ValidationException::withMessages(['feedback' => __('office.feedback.stale')]);
        }

        // The thread's latest analysis must also be the group's latest done one (a later run replaced this draft otherwise).
        $latest = TelegramGroupAnalysis::query()->where('telegram_group_id', $group->id)->where('status', AnalysisStatus::Done->value)->max('id');

        if ((int) $latest !== $analysis->id) {
            throw ValidationException::withMessages(['feedback' => __('office.feedback.stale')]);
        }

        return $this->upsert(
            ['telegram_group_thread_id' => $thread->id, 'telegram_group_analysis_id' => $analysis->id],
            $values + [
                'business_chat_id' => null,
                'telegram_group_id' => $group->id,
                'agent_version_id' => $thread->agent_version_id ?? $analysis->agent_version_id,
                'role' => $thread->role,
                'draft_text' => mb_substr((string) $thread->draft, 0, 1500),
            ],
        );
    }

    /**
     * One row per draft: two submits at the same moment both try to insert, the loser of the unique key
     * simply updates the winner's row (last writer wins), never a 500.
     *
     * @param  array<string, int>  $keys
     * @param  array<string, mixed>  $values
     */
    private function upsert(array $keys, array $values): OfficeDraftFeedback
    {
        try {
            return OfficeDraftFeedback::query()->updateOrCreate($keys, $values);
        } catch (UniqueConstraintViolationException) {
            return OfficeDraftFeedback::query()->updateOrCreate($keys, $values);
        }
    }

    private function clean(mixed $value, int $max): ?string
    {
        $clean = is_string($value) ? ChatRedactor::clean($value, $max) : '';

        return $clean === '' ? null : $clean;
    }
}
