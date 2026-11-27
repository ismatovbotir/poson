<?php

use App\Actions\Office\RecordDraftFeedback;
use App\Enums\AgentRole;
use App\Enums\DraftFeedbackReason;
use App\Enums\DraftOutcome;
use App\Enums\Role;
use App\Livewire\Admin\Office\Chats\Show as ChatShow;
use App\Livewire\Admin\Office\Groups\Show as GroupShow;
use App\Models\AgentProfileVersion;
use App\Models\BusinessChat;
use App\Models\BusinessChatAnalysis;
use App\Models\OfficeDraftFeedback;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupAnalysis;
use App\Models\TelegramGroupThread;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/../../../Helpers/Office.php';
require_once __DIR__.'/../../../Helpers/Groups.php';

/*
| Plan 46 T5: RecordDraftFeedback (ED-3, ED46-8) and the feedback form of the chat and group screens.
*/

function feedbackVersion(): AgentProfileVersion
{
    return officeAgentVersion(AgentRole::Sales);
}

/**
 * A client chat assigned to a sales manager with a done analysis that has a draft.
 *
 * @return array{chat: BusinessChat, analysis: BusinessChatAnalysis, sales: User, version: AgentProfileVersion}
 */
function feedbackChat(array $analysis = []): array
{
    $sales = staffUser(Role::SalesManager);
    $chat = BusinessChat::factory()->client($sales)->create();
    $version = feedbackVersion();
    $done = BusinessChatAnalysis::factory()->done()->create(array_merge(['business_chat_id' => $chat->id, 'draft_reply' => 'Assalomu alaykum! Qaysi oʻlcham kerak?', 'agent_version_id' => $version->id], $analysis));

    return ['chat' => $chat, 'analysis' => $done, 'sales' => $sales, 'version' => $version];
}

/**
 * @return array{group: TelegramGroup, thread: TelegramGroupThread, analysis: TelegramGroupAnalysis, sales: User, version: AgentProfileVersion}
 */
function feedbackGroup(): array
{
    $setup = groupForAnalysis();
    $version = feedbackVersion();
    $analysis = TelegramGroupAnalysis::factory()->done()->create(['telegram_group_id' => $setup['group']->id, 'agent_version_id' => $version->id]);
    $thread = TelegramGroupThread::factory()->create(['telegram_group_id' => $setup['group']->id, 'client_member_id' => $setup['client']->id, 'draft' => 'Hello, which size?', 'last_analysis_id' => $analysis->id, 'agent_version_id' => $version->id]);

    return ['group' => $setup['group'], 'thread' => $thread, 'analysis' => $analysis, 'sales' => $setup['sales'], 'version' => $version];
}

it('records feedback on the latest draft with every foreign key derived on the server', function () {
    $f = feedbackChat();

    $row = app(RecordDraftFeedback::class)->handle($f['sales'], $f['chat'], [
        'outcome' => 'edited', 'reason' => 'tone', 'note' => 'Too stiff', 'final_text' => 'Salom! Qaysi oʻlcham kerak?', 'analysis_id' => $f['analysis']->id,
        'business_chat_id' => 999, 'user_id' => 999, 'agent_version_id' => 999,
    ]);

    expect($row->business_chat_id)->toBe($f['chat']->id)
        ->and($row->business_chat_analysis_id)->toBe($f['analysis']->id)
        ->and($row->agent_version_id)->toBe($f['version']->id)
        ->and($row->user_id)->toBe($f['sales']->id)
        ->and($row->role)->toBe(AgentRole::Sales)
        ->and($row->outcome)->toBe(DraftOutcome::Edited)
        ->and($row->reason)->toBe(DraftFeedbackReason::Tone)
        ->and($row->draft_text)->toBe('Assalomu alaykum! Qaysi oʻlcham kerak?')
        ->and($row->final_text)->toBe('Salom! Qaysi oʻlcham kerak?')
        ->and($row->note)->toBe('Too stiff');
});

it('upserts one row per draft, last writer wins, and needs a reason unless used', function () {
    $f = feedbackChat();
    $record = fn (array $data, ?User $user = null) => app(RecordDraftFeedback::class)->handle($user ?? $f['sales'], $f['chat'], $data + ['analysis_id' => $f['analysis']->id]);

    $record(['outcome' => 'used', 'reason' => 'tone', 'final_text' => 'ignored']);
    $admin = staffUser();
    $row = $record(['outcome' => 'rejected', 'reason' => 'wrong_facts'], $admin);

    expect(OfficeDraftFeedback::query()->count())->toBe(1)
        ->and($row->user_id)->toBe($admin->id)
        ->and($row->outcome)->toBe(DraftOutcome::Rejected)
        ->and($row->final_text)->toBeNull();

    $used = $record(['outcome' => 'used', 'reason' => 'tone', 'final_text' => 'ignored']);

    expect($used->reason)->toBeNull()->and($used->final_text)->toBeNull()
        ->and(fn () => $record(['outcome' => 'edited', 'reason' => 'tone']))->toThrow(ValidationException::class)
        ->and(fn () => $record(['outcome' => 'rejected']))->toThrow(ValidationException::class)
        ->and(fn () => $record(['outcome' => 'maybe', 'reason' => 'tone']))->toThrow(ValidationException::class)
        ->and(fn () => $record(['outcome' => 'rejected', 'reason' => 'nope']))->toThrow(ValidationException::class)
        ->and(fn () => $record(['outcome' => 'rejected', 'reason' => 'tone', 'note' => str_repeat('a', 501)]))->toThrow(ValidationException::class)
        ->and(fn () => $record(['outcome' => 'edited', 'reason' => 'tone', 'final_text' => str_repeat('a', 1501)]))->toThrow(ValidationException::class);
});

it('masks contacts and numbers in the note and the final text and refuses an unmasked-empty edit', function () {
    $f = feedbackChat();

    $row = app(RecordDraftFeedback::class)->handle($f['sales'], $f['chat'], [
        'outcome' => 'edited', 'reason' => 'other', 'analysis_id' => $f['analysis']->id,
        'note' => 'Client wrote to +998 90 123 45 67',
        'final_text' => 'Call me at +998 90 123 45 67 or write to someone@example.com, card 8600 1234 5678 9012',
    ]);

    expect($row->note)->not->toContain('998')->and($row->final_text)->not->toContain('998')->not->toContain('someone')->not->toContain('8600');

    expect(fn () => app(RecordDraftFeedback::class)->handle($f['sales'], $f['chat'], ['outcome' => 'edited', 'reason' => 'other', 'analysis_id' => $f['analysis']->id, 'final_text' => '   ']))->toThrow(ValidationException::class);
});

it('refuses a stale, blocked, empty or failed draft', function (Closure $change) {
    $f = feedbackChat();
    $change($f);

    expect(fn () => app(RecordDraftFeedback::class)->handle($f['sales'], $f['chat'], ['outcome' => 'used', 'analysis_id' => $f['analysis']->id]))->toThrow(ValidationException::class);
})->with([
    'a newer analysis exists' => [fn (array $f) => BusinessChatAnalysis::factory()->done()->create(['business_chat_id' => $f['chat']->id, 'draft_reply' => 'Newer'])],
    'blocked' => [fn (array $f) => $f['analysis']->forceFill(['draft_blocked' => true, 'draft_reply' => null])->save()],
    'no draft' => [fn (array $f) => $f['analysis']->forceFill(['draft_reply' => null])->save()],
]);

it('refuses a wrong analysis id, and a thread of another group', function () {
    $f = feedbackChat();
    $other = BusinessChatAnalysis::factory()->done()->create(['draft_reply' => 'Other chat draft']);

    expect(fn () => app(RecordDraftFeedback::class)->handle($f['sales'], $f['chat'], ['outcome' => 'used', 'analysis_id' => $other->id]))->toThrow(ValidationException::class);

    $g = feedbackGroup();
    $foreign = TelegramGroupThread::factory()->create(['draft' => 'Other group', 'last_analysis_id' => TelegramGroupAnalysis::factory()->done()->create()->id]);

    expect(fn () => app(RecordDraftFeedback::class)->handle($g['sales'], $g['group'], ['outcome' => 'used', 'thread_id' => $foreign->id, 'analysis_id' => $foreign->last_analysis_id]))->toThrow(ValidationException::class);
});

it('records feedback on a group thread with the thread, analysis and version derived', function () {
    $g = feedbackGroup();

    $row = app(RecordDraftFeedback::class)->handle($g['sales'], $g['group'], ['outcome' => 'rejected', 'reason' => 'brand', 'thread_id' => $g['thread']->id, 'analysis_id' => $g['analysis']->id]);

    expect($row->telegram_group_id)->toBe($g['group']->id)
        ->and($row->business_chat_id)->toBeNull()
        ->and($row->telegram_group_thread_id)->toBe($g['thread']->id)
        ->and($row->telegram_group_analysis_id)->toBe($g['analysis']->id)
        ->and($row->agent_version_id)->toBe($g['version']->id)
        ->and($row->draft_text)->toBe('Hello, which size?');
});

it('stores the texts encrypted at rest', function () {
    $f = feedbackChat();

    app(RecordDraftFeedback::class)->handle($f['sales'], $f['chat'], ['outcome' => 'edited', 'reason' => 'tone', 'note' => 'Plain note', 'final_text' => 'Plain final text', 'analysis_id' => $f['analysis']->id]);
    $raw = (array) DB::table('office_draft_feedback')->first();

    expect($raw['draft_text'])->not->toContain('Qaysi')->and($raw['note'])->not->toContain('Plain')->and($raw['final_text'])->not->toContain('Plain');
});

it('shows the form under the draft of the chat and records it from the screen', function () {
    $f = feedbackChat();

    Livewire::actingAs($f['sales'])->test(ChatShow::class, ['businessChat' => $f['chat']])
        ->assertSeeHtml('data-draft-feedback')
        ->assertSee(__('office.feedback.warning'))
        ->set('feedback.outcome', 'rejected')
        ->set('feedback.reason', 'wrong_role')
        ->call('submitFeedback')
        ->assertHasNoErrors()
        ->assertSet('feedback.outcome', '');

    expect(OfficeDraftFeedback::query()->sole()->reason)->toBe(DraftFeedbackReason::WrongRole);
});

it('shows the form under a thread draft and records it from the screen', function () {
    $g = feedbackGroup();

    Livewire::actingAs($g['sales'])->test(GroupShow::class, ['group' => $g['group']])
        ->assertSeeHtml('data-draft-feedback')
        ->set('feedback.'.$g['thread']->id.'.outcome', 'used')
        ->call('submitFeedback', $g['thread']->id)
        ->assertHasNoErrors();

    expect(OfficeDraftFeedback::query()->sole()->telegram_group_thread_id)->toBe($g['thread']->id);
});

it('hides the form from a user without office.analyse', function () {
    $f = feedbackChat();
    $service = staffUser(Role::ServiceEngineer);
    $f['chat']->forceFill(['assignee_id' => $service->id])->save();

    if (! $service->can('office.analyse')) {
        Livewire::actingAs($service)->test(ChatShow::class, ['businessChat' => $f['chat']])->assertDontSeeHtml('data-draft-feedback');
        expect(fn () => app(RecordDraftFeedback::class)->handle($service, $f['chat'], ['outcome' => 'used', 'analysis_id' => $f['analysis']->id]))->toThrow(AuthorizationException::class);
    } else {
        expect(true)->toBeTrue();
    }

    RateLimiter::clear('office-draft-feedback:'.$service->id);
});
