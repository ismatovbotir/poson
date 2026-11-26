<?php

use App\Enums\GroupMemberRole;
use App\Enums\GroupStatus;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupAnalysis;
use App\Models\TelegramGroupMember;
use App\Models\TelegramGroupMessage;
use App\Models\TelegramGroupThread;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\McpExclusionScanner;

/*
| Plan 44 T3: group schema, models, factories, encryption (OG-11), MCP scan (OG-13).
*/

it('builds every group factory', function () {
    $message = TelegramGroupMessage::factory()->create();

    expect(TelegramGroup::factory()->create()->status)->toBe(GroupStatus::Untracked)
        ->and(TelegramGroup::factory()->tracked()->create()->isTracked())->toBeTrue()
        ->and(TelegramGroup::factory()->ignored()->create()->status)->toBe(GroupStatus::Ignored)
        ->and(TelegramGroup::factory()->untracked()->create()->status)->toBe(GroupStatus::Untracked)
        ->and(TelegramGroup::factory()->botAdmin()->create()->bot_is_admin)->toBeTrue()
        ->and(TelegramGroupMember::factory()->employee()->create()->role)->toBe(GroupMemberRole::Employee)
        ->and(TelegramGroupMember::factory()->client()->create()->role)->toBe(GroupMemberRole::Client)
        ->and(TelegramGroupMember::factory()->unknown()->create()->role)->toBe(GroupMemberRole::Unknown)
        ->and(TelegramGroupMember::factory()->bot()->create()->is_bot)->toBeTrue()
        ->and($message->telegram_group_id)->toBe($message->member->telegram_group_id)
        ->and(TelegramGroupAnalysis::factory()->done()->create()->group)->toBeInstanceOf(TelegramGroup::class)
        ->and(TelegramGroupThread::factory()->create()->group)->toBeInstanceOf(TelegramGroup::class);
});

it('stores message text and summaries encrypted at rest', function () {
    $message = TelegramGroupMessage::factory()->create(['text' => 'Secret group words']);
    $analysis = TelegramGroupAnalysis::factory()->done('Secret summary')->create(['draft_reply' => 'Secret draft']);
    $thread = TelegramGroupThread::factory()->create(['summary' => 'Secret thread', 'next_step' => 'Secret step', 'draft' => 'Secret thread draft']);

    $rawText = DB::table('telegram_group_messages')->where('id', $message->id)->value('text');
    $rawAnalysis = (array) DB::table('telegram_group_analyses')->where('id', $analysis->id)->first(['summary', 'next_step', 'draft_reply']);
    $rawThread = (array) DB::table('telegram_group_threads')->where('id', $thread->id)->first(['summary', 'next_step', 'draft']);

    expect($rawText)->not->toBe('Secret group words')->not->toContain('Secret')
        ->and(Crypt::decryptString($rawText))->toBe('Secret group words')
        ->and(implode(' ', $rawAnalysis))->not->toContain('Secret')
        ->and(implode(' ', $rawThread))->not->toContain('Secret')
        ->and($analysis->fresh()->draft_reply)->toBe('Secret draft')
        ->and($thread->fresh()->draft)->toBe('Secret thread draft');
});

it('keeps a member unique per group and a message id unique per group', function () {
    $group = TelegramGroup::factory()->tracked()->create();
    $member = TelegramGroupMember::factory()->for($group, 'group')->create(['telegram_id' => 555]);
    $message = TelegramGroupMessage::factory()->inGroup($group)->create(['telegram_message_id' => 77]);

    expect(fn () => TelegramGroupMember::factory()->for($group, 'group')->create(['telegram_id' => 555]))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => TelegramGroupMessage::factory()->create(['telegram_group_id' => $group->id, 'member_id' => $member->id, 'telegram_message_id' => 77]))->toThrow(UniqueConstraintViolationException::class);

    // The same ids in another group are fine.
    $other = TelegramGroup::factory()->tracked()->create();
    TelegramGroupMember::factory()->for($other, 'group')->create(['telegram_id' => 555]);
    TelegramGroupMessage::factory()->inGroup($other)->create(['telegram_message_id' => 77]);

    expect(TelegramGroupMember::query()->where('telegram_id', 555)->count())->toBe(2)
        ->and($message->exists)->toBeTrue();
});

it('deletes members, messages, analyses and threads with the group', function () {
    $group = TelegramGroup::factory()->tracked()->create();
    TelegramGroupMessage::factory()->inGroup($group)->create();
    TelegramGroupAnalysis::factory()->for($group, 'group')->create();
    TelegramGroupThread::factory()->for($group, 'group')->create();

    $group->delete();

    expect(TelegramGroupMember::query()->count())->toBe(0)
        ->and(TelegramGroupMessage::query()->count())->toBe(0)
        ->and(TelegramGroupAnalysis::query()->count())->toBe(0)
        ->and(TelegramGroupThread::query()->count())->toBe(0);
});

it('nulls the optional links when the linked row is deleted', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $lead = Lead::factory()->create();
    $group = TelegramGroup::factory()->tracked($user)->create(['customer_id' => $customer->id, 'tracked_by_id' => $user->id]);
    $member = TelegramGroupMember::factory()->client()->for($group, 'group')->create(['staff_user_id' => $user->id, 'customer_id' => $customer->id, 'role_set_by_id' => $user->id]);
    $analysis = TelegramGroupAnalysis::factory()->for($group, 'group')->create(['lead_id' => $lead->id]);
    $thread = TelegramGroupThread::factory()->for($group, 'group')->create([
        'client_member_id' => $member->id, 'lead_id' => $lead->id, 'customer_id' => $customer->id, 'last_analysis_id' => $analysis->id,
    ]);

    $analysis->delete();
    expect($thread->fresh()->last_analysis_id)->toBeNull();

    $member->delete();
    expect($thread->fresh()->client_member_id)->toBeNull();

    $lead->delete();
    $customer->delete();
    expect($thread->fresh()->lead_id)->toBeNull()
        ->and($thread->fresh()->customer_id)->toBeNull()
        ->and($group->fresh()->customer_id)->toBeNull();

    $user->delete();
    expect($group->fresh()->assignee_id)->toBeNull()
        ->and($group->fresh()->tracked_by_id)->toBeNull();
});

it('nulls the member links when a staff user or customer is deleted', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $member = TelegramGroupMember::factory()->create(['staff_user_id' => $user->id, 'customer_id' => $customer->id, 'role_set_by_id' => $user->id]);

    $user->delete();
    $customer->delete();

    expect($member->fresh()->staff_user_id)->toBeNull()
        ->and($member->fresh()->customer_id)->toBeNull()
        ->and($member->fresh()->role_set_by_id)->toBeNull();
});

it('has uz, ru and en labels for the group enums', function () {
    foreach (['uz', 'ru', 'en'] as $locale) {
        app()->setLocale($locale);

        foreach ([...GroupStatus::cases(), ...GroupMemberRole::cases()] as $case) {
            $key = ($case instanceof GroupStatus ? 'group_status.' : 'group_member_role.').$case->value;

            expect($case->label())->not->toBe("enums.{$key}")->not->toBe('', "{$locale} {$key}")
                ->and($case->color())->not->toBe('');
        }
    }
});

it('keeps the group models and tables out of MCP and catches a fixture (abuse case 16)', function () {
    expect(McpExclusionScanner::CRM_MODELS)->toContain('TelegramGroup', 'TelegramGroupMember', 'TelegramGroupMessage', 'TelegramGroupAnalysis', 'TelegramGroupThread')
        ->and(McpExclusionScanner::labels('<?php return DB::table("telegram_groups")->count();', 'app/Mcp/Tools/Fixture.php'))->toContain('CRM table name', 'telegram_group')
        ->and(McpExclusionScanner::labels('<?php return DB::table("x")->where("telegram_group_messages.id", 1);', 'app/Mcp/Tools/Fixture.php'))->toContain('CRM table name')
        ->and(McpExclusionScanner::labels('<?php return $row->where("telegram_group_id", 1);', 'app/Mcp/Tools/Fixture.php'))->toContain('telegram_group');
});
