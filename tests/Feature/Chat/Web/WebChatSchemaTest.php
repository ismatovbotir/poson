<?php

use App\Enums\ChatAiRole;
use App\Enums\ChatAuthor;
use App\Enums\ChatChannel;
use App\Enums\Role;
use App\Models\ChatAiTurn;
use App\Models\User;
use App\Models\WebChat;
use App\Models\WebChatMessage;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 28 C5a §19.2: the web chat tables, the Plan 33 B hooks, the
 * chat_ai_turns foreign key (W-10) and the CB-21 inbox scope.
 */
it('creates the web chat tables with the retention and Plan 33 B hook columns', function () {
    expect(Schema::hasColumns('web_chats', [
        'token_hash', 'locale', 'name', 'phone', 'consent_at', 'consent_version', 'mode', 'dialog', 'conversation_status',
        'manager_id', 'unread_count', 'last_message_at', 'last_inbound_at', 'last_staff_reply_at', 'last_alert_at',
        'handoff_at', 'closed_at', 'ai_context_at', 'ip_hash', 'first_page', 'lead_id', 'customer_id', 'banned_at',
        'banned_by_id', 'purged_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('web_chat_messages', ['web_chat_id', 'author', 'text', 'buttons', 'chat_rule_id', 'user_id', 'lead_id', 'chat_ai_turn_id', 'created_at']))->toBeTrue()
        ->and(Schema::hasColumn('web_chat_messages', 'updated_at'))->toBeFalse();
});

it('W10_ai_turn_fk_cascades_and_messages_go_with_the_chat', function () {
    $chat = WebChat::factory()->create();
    $chat->messages()->create(['author' => ChatAuthor::Visitor, 'text' => 'hi']);
    $turn = ChatAiTurn::query()->create(['channel' => ChatChannel::Web, 'web_chat_id' => $chat->id, 'role' => ChatAiRole::User, 'text' => 'hi']);
    $bot = $chat->messages()->create(['author' => ChatAuthor::Bot, 'text' => 'answer', 'chat_ai_turn_id' => $turn->id]);

    $turn->delete();
    expect($bot->fresh()->chat_ai_turn_id)->toBeNull();

    ChatAiTurn::query()->create(['channel' => ChatChannel::Web, 'web_chat_id' => $chat->id, 'role' => ChatAiRole::User, 'text' => 'hi']);
    $chat->delete();

    expect(ChatAiTurn::query()->count())->toBe(0)
        ->and(WebChatMessage::query()->count())->toBe(0);
});

it('hides the token and IP hashes from serialization and assigns them only explicitly', function () {
    $chat = WebChat::factory()->create();

    expect($chat->toArray())->not->toHaveKeys(['token_hash', 'ip_hash'])
        ->and((new WebChat)->isFillable('token_hash'))->toBeFalse()
        ->and((new WebChat)->isFillable('customer_id'))->toBeFalse()
        ->and((new WebChat)->isFillable('consent_at'))->toBeFalse()
        ->and((new WebChat)->isFillable('banned_at'))->toBeFalse();
});

it('sales_sees_only_handed_off_unassigned_or_own_and_bot_only_chat_hidden_without_inbox_all', function () {
    $sales = staffUser(Role::SalesManager);
    $other = User::factory()->create();
    $botOnly = WebChat::factory()->create();
    $unassigned = WebChat::factory()->handedOff()->create();
    $own = WebChat::factory()->handedOff()->assignedTo($sales)->create();
    $others = WebChat::factory()->handedOff()->assignedTo($other)->create();

    expect(WebChat::query()->visibleTo($sales)->pluck('id')->sort()->values()->all())->toBe([$unassigned->id, $own->id])
        ->and($sales->can('view', $botOnly))->toBeFalse()
        ->and($sales->can('view', $others))->toBeFalse()
        ->and($sales->can('view', $own))->toBeTrue()
        ->and($sales->can('convert', $unassigned))->toBeTrue()
        ->and(WebChat::query()->visibleTo(staffUser(Role::Admin))->count())->toBe(4)
        ->and(WebChat::query()->visibleTo(staffUser(Role::ServiceEngineer))->count())->toBe(0)
        ->and(WebChat::query()->visibleTo(staffUser(Role::ContentManager))->count())->toBe(0);
});
