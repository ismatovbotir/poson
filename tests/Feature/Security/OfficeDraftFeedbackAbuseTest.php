<?php

use App\Actions\Office\ForgetDraftFeedback;
use App\Actions\Office\ForgetGroupMember;
use App\Actions\Office\MarkBusinessChat;
use App\Actions\Office\PruneBusinessData;
use App\Actions\Office\PurgeGroupData;
use App\Actions\Office\RecordDraftFeedback;
use App\Actions\Office\UntrackGroup;
use App\Actions\Office\WipeBusinessData;
use App\Enums\BusinessChatKind;
use App\Enums\Role;
use App\Jobs\WipeBusinessDataChunk;
use App\Jobs\WipeGroupDataChunk;
use App\Livewire\Admin\Office\Chats\Show;
use App\Models\BusinessChat;
use App\Models\BusinessChatAnalysis;
use App\Models\OfficeDraftFeedback;
use App\Models\TelegramGroupThread;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Helpers/Office.php';
require_once __DIR__.'/../../Helpers/Groups.php';
require_once __DIR__.'/../Office/Education/DraftFeedbackTest.php';
require_once __DIR__.'/../Office/BusinessIngestTest.php';

/*
| Plan 46 §9, abuse cases 14 to 17 (ED46-8): access, masking, the cascade sites and the throttle.
*/

/**
 * @return array<string, ?string> the three staff texts of the only feedback row (raw column values)
 */
function feedbackTexts(): array
{
    return (array) DB::table('office_draft_feedback')->first(['note', 'draft_text', 'final_text']);
}

function feedbackWithTexts(int $chatAnalysisId, int $chatId): OfficeDraftFeedback
{
    return OfficeDraftFeedback::factory()->edited('Final secret text')->create(['business_chat_id' => $chatId, 'business_chat_analysis_id' => $chatAnalysisId, 'note' => 'A note']);
}

it('abuse 14: a tampered analysis id on an unassigned chat is 403, a stale one is refused', function () {
    $f = feedbackChat();
    $other = staffUser(Role::SalesManager);

    expect(fn () => app(RecordDraftFeedback::class)->handle($other, $f['chat'], ['outcome' => 'used', 'analysis_id' => $f['analysis']->id]))->toThrow(AuthorizationException::class)
        ->and(OfficeDraftFeedback::query()->count())->toBe(0);

    $f['chat']->forceFill(['assignee_id' => null])->save();
    expect(fn () => app(RecordDraftFeedback::class)->handle($f['sales'], $f['chat'], ['outcome' => 'used', 'analysis_id' => $f['analysis']->id]))->toThrow(AuthorizationException::class);

    Livewire\Livewire::actingAs($other)->test(Show::class, ['businessChat' => $f['chat']])->assertForbidden();

    $g = feedbackGroup();
    $foreignGroup = groupTracked(-1001234500999, ['assignee_id' => staffUser(Role::SalesManager)->id]);

    expect(fn () => app(RecordDraftFeedback::class)->handle($f['sales'], $foreignGroup, ['outcome' => 'used', 'thread_id' => $g['thread']->id, 'analysis_id' => $g['analysis']->id]))->toThrow(AuthorizationException::class);
});

it('abuse 14: the visibility is checked at submit, not only when the page opened', function () {
    $f = feedbackChat();

    $f['chat']->forceFill(['assignee_id' => staffUser(Role::SalesManager)->id])->save();

    expect(fn () => app(RecordDraftFeedback::class)->handle($f['sales'], $f['chat'], ['outcome' => 'used', 'analysis_id' => $f['analysis']->id]))->toThrow(AuthorizationException::class);
});

it('abuse 15: a card number in the final text is stored masked and the raw columns are not plaintext', function () {
    $f = feedbackChat();

    $row = app(RecordDraftFeedback::class)->handle($f['sales'], $f['chat'], [
        'outcome' => 'edited', 'reason' => 'other', 'analysis_id' => $f['analysis']->id,
        'final_text' => 'Pay to card 8600 1234 5678 9012 please', 'note' => 'He gave 8600123456789012',
    ]);

    $raw = feedbackTexts();

    expect($row->fresh()->final_text)->not->toContain('8600')->and($row->fresh()->final_text)->toContain('#')
        ->and($row->fresh()->note)->not->toContain('8600')
        ->and($raw['final_text'])->not->toContain('please')->not->toContain('#')
        ->and($raw['draft_text'])->not->toContain('Assalomu')
        ->and($raw['note'])->not->toContain('He gave');
});

it('abuse 16: unmarking a chat nulls the texts before the analyses go (the row stays as a count)', function () {
    $f = feedbackChat();
    $row = feedbackWithTexts($f['analysis']->id, $f['chat']->id);
    $admin = staffUser();
    confirmAdminPassword();

    app(MarkBusinessChat::class)->handle($admin, $f['chat'], BusinessChatKind::Employee);

    $raw = feedbackTexts();

    expect(OfficeDraftFeedback::query()->count())->toBe(1)
        ->and($raw)->toBe(['note' => null, 'draft_text' => null, 'final_text' => null])
        ->and($row->fresh()->outcome->value)->toBe('edited');
});

it('abuse 16: wipe-all leaves no feedback', function () {
    $f = feedbackChat();
    feedbackWithTexts($f['analysis']->id, $f['chat']->id);
    $admin = staffUser();
    confirmAdminPassword();

    app(WipeBusinessData::class)->handle($admin);
    (WipeBusinessDataChunk::forNow())->handle();

    expect(OfficeDraftFeedback::query()->count())->toBe(0);
});

it('abuse 16: a Telegram message delete nulls the feedback of the affected analyses only', function () {
    $connection = officeIngestReady();
    $chat = BusinessChat::factory()->client()->create(['business_connection_id' => $connection->id, 'telegram_chat_id' => OFFICE_CLIENT_ID]);
    officeHandle(officeFixture('message_client', 1));
    $message = $chat->messages()->sole();
    $affected = BusinessChatAnalysis::factory()->done()->create(['business_chat_id' => $chat->id, 'up_to_message_id' => $message->id, 'draft_reply' => 'Draft']);
    $older = BusinessChatAnalysis::factory()->done()->create(['business_chat_id' => $chat->id, 'up_to_message_id' => $message->id - 1, 'draft_reply' => 'Older']);
    feedbackWithTexts($affected->id, $chat->id);
    $keep = feedbackWithTexts($older->id, $chat->id);

    officeHandle(officeFixture('messages_deleted', 2));

    expect(OfficeDraftFeedback::query()->whereKey($keep->id)->value('final_text'))->not->toBeNull()
        ->and(OfficeDraftFeedback::query()->where('business_chat_analysis_id', $affected->id)->first()->final_text)->toBeNull()
        ->and(OfficeDraftFeedback::query()->where('business_chat_analysis_id', $affected->id)->first()->note)->toBeNull();
});

it('abuse 16: Forget a group member nulls the feedback of every thread of that group, not other groups', function () {
    $g = feedbackGroup();
    $second = TelegramGroupThread::factory()->create(['telegram_group_id' => $g['group']->id, 'client_member_id' => null, 'draft' => 'Second', 'last_analysis_id' => $g['analysis']->id]);
    $mine = OfficeDraftFeedback::factory()->edited('Group final')->create(['business_chat_id' => null, 'business_chat_analysis_id' => null, 'telegram_group_id' => $g['group']->id, 'telegram_group_thread_id' => $g['thread']->id, 'telegram_group_analysis_id' => $g['analysis']->id, 'note' => 'n']);
    $otherThread = OfficeDraftFeedback::factory()->edited('Group final 2')->create(['business_chat_id' => null, 'business_chat_analysis_id' => null, 'telegram_group_id' => $g['group']->id, 'telegram_group_thread_id' => $second->id, 'telegram_group_analysis_id' => $g['analysis']->id, 'note' => 'n']);
    $foreign = OfficeDraftFeedback::factory()->edited('Foreign')->create();
    $unknown = $g['group']->members()->where('role', 'unknown')->first();
    confirmAdminPassword();

    app(ForgetGroupMember::class)->handle(staffUser(), $g['group'], $unknown->id);

    expect($mine->fresh()->final_text)->toBeNull()->and($otherThread->fresh()->final_text)->toBeNull()->and($otherThread->fresh()->note)->toBeNull()
        ->and($foreign->fresh()->final_text)->toBe('Foreign');
});

it('abuse 16: untracking and wiping a group null the feedback texts', function (string $how) {
    $g = feedbackGroup();
    $row = OfficeDraftFeedback::factory()->edited('Group final')->create(['business_chat_id' => null, 'business_chat_analysis_id' => null, 'telegram_group_id' => $g['group']->id, 'telegram_group_thread_id' => $g['thread']->id, 'telegram_group_analysis_id' => $g['analysis']->id, 'draft_text' => 'Snap', 'note' => 'n']);

    if ($how === 'untrack') {
        confirmAdminPassword();
        app(UntrackGroup::class)->handle(staffUser(), $g['group']);
    } else {
        (WipeGroupDataChunk::forNow())->handle(app(PurgeGroupData::class));
    }

    expect(feedbackTexts())->toBe(['note' => null, 'draft_text' => null, 'final_text' => null])
        ->and($row->fresh()->outcome->value)->toBe('edited');
})->with(['untrack', 'wipe']);

it('abuse 16: retention nulls texts 30 days after the last update (promoted too) and rows whose analysis link is gone', function () {
    $f = feedbackChat();
    $old = feedbackWithTexts($f['analysis']->id, $f['chat']->id);
    $old->forceFill(['promoted_at' => now(), 'updated_at' => now()->subDays(31)])->saveQuietly();
    $fresh = OfficeDraftFeedback::factory()->edited('Fresh')->create(['business_chat_id' => $f['chat']->id, 'business_chat_analysis_id' => BusinessChatAnalysis::factory()->done()->create(['business_chat_id' => $f['chat']->id])->id]);
    $orphan = OfficeDraftFeedback::factory()->edited('Orphan')->create(['business_chat_id' => $f['chat']->id, 'business_chat_analysis_id' => null]);

    $counts = app(PruneBusinessData::class)->handle();

    expect($old->fresh()->final_text)->toBeNull()->and($old->fresh()->promoted_at)->not->toBeNull()
        ->and($orphan->fresh()->final_text)->toBeNull()
        ->and($fresh->fresh()->final_text)->toBe('Fresh')
        ->and($counts['feedback'])->toBe(2)
        ->and(ForgetDraftFeedback::prune())->toBe(0);
});

it('abuse 16: deleting the chat or the group deletes its feedback rows', function () {
    $f = feedbackChat();
    feedbackWithTexts($f['analysis']->id, $f['chat']->id);
    $f['chat']->delete();

    expect(OfficeDraftFeedback::query()->count())->toBe(0);

    $g = feedbackGroup();
    OfficeDraftFeedback::factory()->create(['business_chat_id' => null, 'business_chat_analysis_id' => null, 'telegram_group_id' => $g['group']->id, 'telegram_group_thread_id' => $g['thread']->id, 'telegram_group_analysis_id' => $g['analysis']->id]);
    $g['group']->delete();

    expect(OfficeDraftFeedback::query()->count())->toBe(0);
});

it('abuse 17: the 31st feedback in an hour is refused, per user', function () {
    $f = feedbackChat();
    $record = fn (User $user) => app(RecordDraftFeedback::class)->handle($user, $f['chat'], ['outcome' => 'used', 'analysis_id' => $f['analysis']->id]);
    $admin = staffUser();

    foreach (range(1, 30) as $_) {
        $record($f['sales']);
    }

    expect(fn () => $record($f['sales']))->toThrow(ValidationException::class)
        ->and($record($admin)->user_id)->toBe($admin->id);
});
