<?php

use App\Actions\Telegram\InboxRefused;
use App\Actions\Telegram\ResendTelegramMessage;
use App\Actions\Telegram\SendTelegramReply;
use App\Enums\ActivityType;
use App\Enums\ConversationStatus;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Enums\TelegramMessageStatus;
use App\Jobs\DeliverBotMessage;
use App\Livewire\Admin\Telegram\Chats\Show;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Models\User;
use App\Support\Telegram\Bot\BotSwitch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Plan 21 A2: staff replies and resend (T21-12, T21-13, T21-25).
 */
beforeEach(function () {
    telegramBotConfigured();
    replyBotSwitch(true);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]])]);
});

afterEach(function () {
    TelegramUser::flushEventListeners();
    TelegramMessage::flushEventListeners();
});

function replyBotSwitch(bool $on): void
{
    Setting::query()->updateOrCreate(['key' => BotSwitch::KEY], ['group' => SettingsArea::TelegramBot->group(), 'value' => $on]);
}

function replyManager(): User
{
    return staffUser(Role::SalesManager);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function replyThread(array $attributes = [], ?User $manager = null): TelegramUser
{
    $factory = TelegramUser::factory()->registered();

    return ($manager ? $factory->assigned($manager) : $factory)->create($attributes);
}

function replyOwnFailed(TelegramUser $chat, ?User $author): TelegramMessage
{
    return TelegramMessage::factory()->for($chat)->outbound($author)->failed(500)->create(['text' => 'out text', 'sent_at' => null]);
}

/**
 * @return list<Request>
 */
function replySent(): array
{
    return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/sendMessage'))->map(fn (array $pair) => $pair[0])->all();
}

it('sends a reply as plain text with no parse_mode to the customer', function () {
    $user = replyManager();
    $chat = replyThread(['telegram_id' => 987654321], $user);

    Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat])
        ->set('reply', "  <b>Hello</b>\nsecond line  ")
        ->call('sendReply')
        ->assertHasNoErrors()
        ->assertSet('reply', '')
        ->assertDispatched('toast');

    $sent = replySent();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['chat_id'])->toBe('987654321')
        ->and($sent[0]['text'])->toBe("<b>Hello</b>\nsecond line")
        ->and($sent[0]->data())->not->toHaveKey('parse_mode');

    $row = TelegramMessage::query()->where('telegram_user_id', $chat->id)->sole();
    expect($row->status)->toBe(TelegramMessageStatus::Sent)
        ->and($row->user_id)->toBe($user->id)
        ->and($chat->fresh()->last_message_at)->not->toBeNull();
});

it('allows reply for admin and sales manager and refuses other roles and foreign threads', function (Role $role, bool $allowed) {
    Bus::fake([DeliverBotMessage::class]);
    $user = staffUser($role);
    $chat = replyThread();

    if (! $allowed) {
        expect(fn () => app(SendTelegramReply::class)->handle($chat, $user, 'hi'))->toThrow(AuthorizationException::class);
        Bus::assertNotDispatched(DeliverBotMessage::class);

        return;
    }

    expect(app(SendTelegramReply::class)->handle($chat, $user, 'hi')->user_id)->toBe($user->id);
    Bus::assertDispatched(DeliverBotMessage::class);
})->with([
    'admin' => [Role::Admin, true],
    'sales manager' => [Role::SalesManager, true],
    'content manager' => [Role::ContentManager, false],
    'service engineer' => [Role::ServiceEngineer, false],
]);

it('refuses a reply on another manager\'s thread, also from a stale model (T21-12)', function () {
    Bus::fake([DeliverBotMessage::class]);
    $user = replyManager();
    $chat = replyThread();
    $stale = TelegramUser::query()->findOrFail($chat->id);
    $chat->forceFill(['manager_id' => replyManager()->id])->save();

    expect(fn () => app(SendTelegramReply::class)->handle($stale, $user, 'hi'))->toThrow(AuthorizationException::class);

    expect(TelegramMessage::query()->count())->toBe(0);
    Bus::assertNotDispatched(DeliverBotMessage::class);
});

it('assigns an unassigned thread to the first replier and logs ids only', function () {
    Bus::fake([DeliverBotMessage::class]);
    $user = replyManager();
    $chat = replyThread();

    app(SendTelegramReply::class)->handle($chat, $user, 'secret customer text');

    expect($chat->fresh()->manager_id)->toBe($user->id);

    $activity = $chat->activities()->sole();
    expect($activity->type)->toBe(ActivityType::Assignment)
        ->and($activity->user_id)->toBe($user->id)
        ->and($activity->meta)->toBe(['action' => 'first_reply', 'from_id' => null, 'to_id' => $user->id])
        ->and(json_encode($activity->toArray()))->not->toContain('secret customer text');

    app(SendTelegramReply::class)->handle($chat->fresh(), $user, 'again');
    expect($chat->activities()->count())->toBe(1);
});

it('does not reassign when an Admin replies on another manager\'s thread', function () {
    Bus::fake([DeliverBotMessage::class]);
    $manager = replyManager();
    $chat = replyThread(manager: $manager);

    app(SendTelegramReply::class)->handle($chat, staffUser(Role::Admin), 'hi');

    expect($chat->fresh()->manager_id)->toBe($manager->id)->and($chat->activities()->count())->toBe(0);
});

it('refuses when the first-reply assignment loses the race (T21-12)', function () {
    Bus::fake([DeliverBotMessage::class]);
    $winner = replyManager();
    $actor = replyManager();
    $chat = replyThread();

    TelegramUser::retrieved(function () use ($chat, $winner): void {
        TelegramUser::query()->whereKey($chat->id)->update(['manager_id' => $winner->id]);
    });

    expect(fn () => app(SendTelegramReply::class)->handle($chat, $actor, 'hi'))
        ->toThrow(fn (InboxRefused $refused) => expect($refused->reason)->toBe('taken'));

    TelegramUser::flushEventListeners();

    expect(TelegramMessage::query()->count())->toBe(0)->and($chat->activities()->count())->toBe(0);
    Bus::assertNotDispatched(DeliverBotMessage::class);
});

it('leaves an unassigned thread unassigned when an Admin replies', function () {
    Bus::fake([DeliverBotMessage::class]);
    $chat = replyThread();

    expect(app(SendTelegramReply::class)->handle($chat, staffUser(Role::Admin), 'hi'))->not->toBeNull()
        ->and($chat->fresh()->manager_id)->toBeNull()
        ->and($chat->activities()->count())->toBe(0);
});

it('reopens a closed thread on a staff reply, with an Activity row', function () {
    Bus::fake([DeliverBotMessage::class]);
    $user = replyManager();
    $chat = TelegramUser::factory()->registered()->closed()->assigned($user)->create();

    app(SendTelegramReply::class)->handle($chat, $user, 'hi');

    expect($chat->fresh()->conversation_status)->toBe(ConversationStatus::Open)
        ->and($chat->fresh()->closed_at)->toBeNull()
        ->and($chat->activities()->sole()->type)->toBe(ActivityType::StatusChange)
        ->and($chat->activities()->sole()->meta)->toBe(['from' => 'closed', 'to' => 'open', 'via' => 'reply']);
});

it('sends one message for two sendReply calls with the same token, and a repeat later still sends', function () {
    Bus::fake([DeliverBotMessage::class]);
    $user = replyManager();
    $chat = replyThread(manager: $user);
    $component = Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat]);

    $component->set('reply', 'same text')->call('sendReply', 'token-1')->assertSet('reply', '');
    $component->set('reply', 'same text')->call('sendReply', 'token-1')->assertSet('reply', '');
    $component->call('sendReply', 'token-1')->assertHasNoErrors();

    expect(TelegramMessage::query()->count())->toBe(1)
        ->and(RateLimiter::attempts(SendTelegramReply::LIMIT_KEY.$user->id))->toBe(1);

    $component->set('reply', 'same text')->call('sendReply', 'token-2');
    expect(TelegramMessage::query()->count())->toBe(2);

    $this->travel(SendTelegramReply::ONCE_SECONDS + 1)->seconds();
    $component->set('reply', 'same text')->call('sendReply', 'token-1');
    expect(TelegramMessage::query()->count())->toBe(3);
});

it('keeps the draft on a failure after the claim point, and an immediate retry sends exactly one', function () {
    Bus::fake([DeliverBotMessage::class]);
    $user = replyManager();
    $chat = replyThread(manager: $user);
    $component = Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat])->set('reply', 'important text');

    TelegramMessage::creating(function (): void {
        throw new InboxRefused('taken');
    });

    $component->call('sendReply', 'token-f')->assertDispatched('toast', type: 'error')->assertSet('reply', 'important text');
    expect(TelegramMessage::query()->count())->toBe(0)
        ->and((int) RateLimiter::attempts(SendTelegramReply::LIMIT_KEY.$user->id))->toBe(0);

    TelegramMessage::flushEventListeners();

    $component->call('sendReply', 'token-f')->assertDispatched('toast', type: 'success')->assertSet('reply', '');
    expect(TelegramMessage::query()->count())->toBe(1);
});

it('frees the token after a database error too', function () {
    Bus::fake([DeliverBotMessage::class]);
    $user = replyManager();
    $chat = replyThread(manager: $user);
    $action = app(SendTelegramReply::class);

    TelegramMessage::creating(function (): void {
        throw new RuntimeException('database down');
    });

    expect(fn () => $action->handle($chat, $user, 'text', 'token-d'))->toThrow(RuntimeException::class);
    TelegramMessage::flushEventListeners();

    expect($action->handle($chat, $user, 'text', 'token-d'))->not->toBeNull()
        ->and($action->handle($chat, $user, 'text', 'token-d'))->toBeNull()
        ->and(TelegramMessage::query()->count())->toBe(1);
});

it('sends a different text with a reused token', function () {
    Bus::fake([DeliverBotMessage::class]);
    $user = replyManager();
    $chat = replyThread(manager: $user);
    $component = Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat]);

    $component->set('reply', 'first text')->call('sendReply', 'token-r');
    $component->set('reply', 'second text')->call('sendReply', 'token-r')->assertSet('reply', '');

    expect(TelegramMessage::query()->pluck('text')->all())->toBe(['first text', 'second text']);
});

it('tells the user a true duplicate was already sent', function () {
    Bus::fake([DeliverBotMessage::class]);
    $user = replyManager();
    $chat = replyThread(manager: $user);
    $component = Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat]);

    $component->set('reply', 'same text')->call('sendReply', 'token-t');
    $component->set('reply', 'same text')->call('sendReply', 'token-t')
        ->assertDispatched('toast', title: __('telegram_inbox.toasts.already_sent'), type: 'info')
        ->assertSet('reply', '');

    expect(TelegramMessage::query()->count())->toBe(1);
});

it('ignores a repeated submit with no token within the window', function () {
    Bus::fake([DeliverBotMessage::class]);
    $user = replyManager();
    $chat = replyThread(manager: $user);
    $component = Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat]);

    $component->set('reply', 'same text')->call('sendReply');
    $component->set('reply', 'same text')->call('sendReply');

    expect(TelegramMessage::query()->count())->toBe(1);
});

it('uses no limit on a resend that changes no row', function () {
    Bus::fake([DeliverBotMessage::class]);
    $author = replyManager();
    $chat = replyThread(manager: $author);
    $row = replyOwnFailed($chat, $author);

    TelegramMessage::retrieved(function () use ($row): void {
        TelegramMessage::query()->whereKey($row->id)->update(['status' => TelegramMessageStatus::Pending, 'updated_at' => now()]);
    });

    expect(app(ResendTelegramMessage::class)->handle($row, $author))->toBeFalse()
        ->and((int) RateLimiter::attempts(SendTelegramReply::LIMIT_KEY.$author->id))->toBe(0);
});

it('keeps the draft through refreshThread', function () {
    $user = replyManager();
    $chat = replyThread(manager: $user);

    Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat])
        ->set('reply', 'half written')
        ->call('refreshThread')
        ->assertSet('reply', 'half written');
});

it('hides the resend button while the bot is off', function () {
    $author = replyManager();
    $chat = replyThread(manager: $author);
    replyOwnFailed($chat, $author);

    Livewire::actingAs($author)->test(Show::class, ['telegramUser' => $chat])->assertSeeHtml('data-resend=');

    replyBotSwitch(false);

    Livewire::actingAs($author)->test(Show::class, ['telegramUser' => $chat])->assertDontSeeHtml('data-resend=');
});

it('keeps the poll cheap: no UPDATE at 0 unread, no manager list or customer query', function () {
    $user = staffUser(Role::Admin);
    $customer = Customer::factory()->create();
    $chat = replyThread(['customer_id' => $customer->id, 'unread_count' => 0]);
    TelegramMessage::factory()->for($chat)->count(3)->create();
    $component = Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $component->call('refreshThread');
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries->filter(fn (string $sql) => str_starts_with(strtolower($sql), 'update')))->toBeEmpty()
        ->and($queries->filter(fn (string $sql) => str_contains($sql, 'model_has_permissions') && str_contains($sql, '"is_active"')))->toBeEmpty()
        ->and($queries->filter(fn (string $sql) => str_contains($sql, '"customers"') && str_contains($sql, 'like')))->toBeEmpty()
        ->and($queries->filter(fn (string $sql) => str_contains($sql, '"activities"')))->toBeEmpty()
        ->and($queries->count())->toBeLessThanOrEqual(12);
});

it('refuses server-side when delivery cannot happen, for an Admin too (T21-12, T21-25)', function (array $attributes, bool $botOn, ?array $secrets, string $reason) {
    Bus::fake([DeliverBotMessage::class]);
    replyBotSwitch($botOn);

    if ($secrets !== null) {
        telegramBotConfigured($secrets);
    }

    $chat = TelegramUser::factory()->registered()->create($attributes);

    expect(fn () => app(SendTelegramReply::class)->handle($chat, staffUser(Role::Admin), 'hi'))
        ->toThrow(fn (InboxRefused $refused) => expect($refused->reason)->toBe($reason));

    expect(TelegramMessage::query()->count())->toBe(0)
        ->and($chat->fresh()->manager_id)->toBeNull();
    Bus::assertNotDispatched(DeliverBotMessage::class);
})->with([
    'unregistered' => [['registered_at' => null], true, null, 'unregistered'],
    'banned' => [['banned_at' => now()], true, null, 'banned'],
    'blocked' => [['blocked_at' => now()], true, null, 'blocked'],
    'bot off' => [[], false, null, 'bot_off'],
    'no token' => [[], true, ['services.telegram.bot_token' => ''], 'bot_off'],
]);

it('re-checks state under the lock, not from the model passed in', function () {
    Bus::fake([DeliverBotMessage::class]);
    $user = replyManager();
    $chat = replyThread(manager: $user);
    $stale = TelegramUser::query()->findOrFail($chat->id);
    $chat->forceFill(['banned_at' => now()])->save();

    expect(fn () => app(SendTelegramReply::class)->handle($stale, $user, 'hi'))->toThrow(InboxRefused::class);
    Bus::assertNotDispatched(DeliverBotMessage::class);
});

it('disables the reply box with a banner and a direct call changes nothing', function (array $attributes, bool $botOn, string $banner) {
    replyBotSwitch($botOn);
    $user = staffUser(Role::Admin);
    $chat = TelegramUser::factory()->registered()->create($attributes);

    $component = Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat]);
    $component->assertSeeHtml('data-banner="'.$banner.'"');

    expect($component->html())->toMatch('/<textarea[^>]*\sdisabled/')
        ->toMatch('/<button[^>]*\sdisabled[^>]*data-reply-send/');

    $component->set('reply', 'hi')->call('sendReply')->assertDispatched('toast', type: 'error');

    expect(TelegramMessage::query()->count())->toBe(0)->and(replySent())->toBe([]);
})->with([
    'bot off' => [[], false, 'bot-off'],
    'blocked' => [['blocked_at' => now()], true, 'blocked'],
    'banned' => [['banned_at' => now()], true, 'banned'],
    'unregistered' => [['registered_at' => null], true, 'unregistered'],
]);

it('keeps the per-user limit inside the action, atomically, only after the checks pass', function () {
    Bus::fake([DeliverBotMessage::class]);
    $user = replyManager();
    $chat = replyThread(manager: $user);
    $action = app(SendTelegramReply::class);

    $chat->forceFill(['banned_at' => now()])->save();
    expect(fn () => $action->handle($chat, $user, 'hi'))->toThrow(InboxRefused::class);
    expect(RateLimiter::attempts(SendTelegramReply::LIMIT_KEY.$user->id))->toBe(0);
    $chat->forceFill(['banned_at' => null])->save();

    foreach (range(1, SendTelegramReply::PER_MINUTE) as $i) {
        $action->handle($chat, $user, 'msg '.$i);
    }

    expect(fn () => $action->handle($chat, $user, 'one more'))
        ->toThrow(fn (InboxRefused $refused) => expect($refused->reason)->toBe('rate_limited'));
    expect(TelegramMessage::query()->count())->toBe(SendTelegramReply::PER_MINUTE);

    $otherAdmin = staffUser(Role::Admin);
    expect($action->handle($chat, $otherAdmin, 'other user')->exists)->toBeTrue();
});

it('validates the reply length on the screen and in the action', function (string $text) {
    Bus::fake([DeliverBotMessage::class]);
    $user = replyManager();
    $chat = replyThread(manager: $user);

    expect(fn () => app(SendTelegramReply::class)->handle($chat, $user, $text))
        ->toThrow(fn (InboxRefused $refused) => expect($refused->reason)->toBe('invalid_text'));

    Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat])->set('reply', $text)->call('sendReply')->assertHasErrors('reply');
    Bus::assertNotDispatched(DeliverBotMessage::class);
})->with([
    'blank' => ['   '],
    'too long' => [str_repeat('a', SendTelegramReply::MAX_LENGTH + 1)],
]);

it('refuses text over Telegram\'s UTF-16 limit and strips control characters', function () {
    Bus::fake([DeliverBotMessage::class]);
    $user = replyManager();
    $chat = replyThread(manager: $user);
    $action = app(SendTelegramReply::class);

    expect(fn () => $action->handle($chat, $user, str_repeat("\u{1F600}", 3000)))->toThrow(InboxRefused::class);
    expect($action->handle($chat, $user, "a\u{0007}b\tc\r\nd")->text)->toBe("ab\tc\nd");
});

it('escapes a reply when rendering the thread', function () {
    $user = replyManager();
    $chat = replyThread(manager: $user);
    TelegramMessage::factory()->for($chat)->outbound($user)->create(['text' => '<script>alert(1)</script>']);

    expect(Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat])->html())
        ->not->toContain('<script>alert(1)')->toContain('&lt;script&gt;alert(1)');
});

it('keeps polling and holds no telegram_id with the reply box in place', function () {
    $user = replyManager();
    $chat = replyThread(['telegram_id' => 7700123456], $user);

    $component = Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat]);
    $component->assertSeeHtml('wire:poll.10s.visible')->assertSeeHtml('data-reply-box');
    $component->set('reply', 'draft')->call('refreshThread')->assertOk()->assertSet('reply', 'draft');

    expect($component->html().json_encode($component->snapshot))->not->toContain('7700123456')->not->toContain('telegram_id');
    expect(fn () => $component->set('conversationId', 999))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('gives 403 on reply after the thread was reassigned mid-session', function () {
    $user = replyManager();
    $chat = replyThread(manager: $user);
    $component = Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat])->set('reply', 'hi');

    $chat->forceFill(['manager_id' => replyManager()->id])->save();

    $component->call('sendReply')->assertForbidden();
    expect(TelegramMessage::query()->count())->toBe(0);
});

/* ---------------------------------------------------------------- resend */

it('lets the author retry a failed reply; another viewer gets 403 and an Admin may', function () {
    $author = replyManager();
    $chat = replyThread();
    $row = replyOwnFailed($chat, $author);

    expect(fn () => app(ResendTelegramMessage::class)->handle($row, replyManager()))->toThrow(AuthorizationException::class);
    expect($row->fresh()->status)->toBe(TelegramMessageStatus::Failed);

    expect(app(ResendTelegramMessage::class)->handle($row, $author))->toBeTrue()
        ->and($row->fresh()->status)->toBe(TelegramMessageStatus::Sent)
        ->and($row->fresh()->error_code)->toBeNull()
        ->and(replySent())->toHaveCount(1);

    $other = replyOwnFailed(replyThread(), $author);
    expect(app(ResendTelegramMessage::class)->handle($other, staffUser(Role::Admin)))->toBeTrue();
});

it('refuses resend for the author once the thread is another manager\'s', function () {
    $author = replyManager();
    $chat = replyThread(manager: replyManager());
    $row = replyOwnFailed($chat, $author);

    expect(fn () => app(ResendTelegramMessage::class)->handle($row, $author))->toThrow(AuthorizationException::class);
});

it('never lets staff retry an ownerless auto-ack, Admin included (T21-13)', function () {
    Bus::fake([DeliverBotMessage::class]);
    $chat = replyThread();
    $row = replyOwnFailed($chat, null);

    expect(fn () => app(ResendTelegramMessage::class)->handle($row, replyManager()))->toThrow(AuthorizationException::class);
    expect(fn () => app(ResendTelegramMessage::class)->handle($row, staffUser(Role::Admin)))
        ->toThrow(fn (InboxRefused $refused) => expect($refused->reason)->toBe('not_retryable'));

    expect($row->fresh()->status)->toBe(TelegramMessageStatus::Failed);
    Bus::assertNotDispatched(DeliverBotMessage::class);

    Livewire::actingAs(staffUser(Role::Admin))->test(Show::class, ['telegramUser' => $chat])->assertDontSeeHtml('data-resend=');
});

it('reclaims only what is failed or old enough', function (TelegramMessageStatus $status, int $minutes, bool $queued) {
    Bus::fake([DeliverBotMessage::class]);
    $author = replyManager();
    $chat = replyThread(manager: $author);
    $row = TelegramMessage::factory()->for($chat)->outbound($author)->create(['status' => $status]);
    TelegramMessage::query()->whereKey($row->id)->update(['updated_at' => now()->subMinutes($minutes)]);

    if ($queued) {
        expect(app(ResendTelegramMessage::class)->handle($row->fresh(), $author))->toBeTrue()
            ->and($row->fresh()->status)->toBe(TelegramMessageStatus::Pending);
        Bus::assertDispatchedTimes(DeliverBotMessage::class, 1);
    } else {
        expect(fn () => app(ResendTelegramMessage::class)->handle($row->fresh(), $author))->toThrow(InboxRefused::class);
        expect($row->fresh()->status)->toBe($status);
        Bus::assertNotDispatched(DeliverBotMessage::class);
    }
})->with([
    'failed' => [TelegramMessageStatus::Failed, 0, true],
    'stuck sending' => [TelegramMessageStatus::Sending, TelegramMessage::STUCK_MINUTES + 1, true],
    'old pending' => [TelegramMessageStatus::Pending, TelegramMessage::STUCK_MINUTES + 1, true],
    'fresh sending' => [TelegramMessageStatus::Sending, 1, false],
    'fresh pending' => [TelegramMessageStatus::Pending, 1, false],
    'sent' => [TelegramMessageStatus::Sent, 60, false],
    'received' => [TelegramMessageStatus::Received, 60, false],
]);

it('sends once when two resend calls start from the same state', function () {
    $author = replyManager();
    $chat = replyThread(manager: $author);
    $row = TelegramMessage::factory()->for($chat)->outbound($author)->create(['status' => TelegramMessageStatus::Sending]);
    TelegramMessage::query()->whereKey($row->id)->update(['updated_at' => now()->subMinutes(30)]);
    [$first, $second] = [$row->fresh(), $row->fresh()];

    expect(app(ResendTelegramMessage::class)->handle($first, $author))->toBeTrue();
    expect(fn () => app(ResendTelegramMessage::class)->handle($second, $author))->toThrow(InboxRefused::class);

    expect(replySent())->toHaveCount(1);
});

it('dispatches nothing when the conditional update loses the race (T21-13)', function () {
    Bus::fake([DeliverBotMessage::class]);
    $author = replyManager();
    $chat = replyThread(manager: $author);
    $row = replyOwnFailed($chat, $author);

    TelegramMessage::retrieved(function () use ($row): void {
        TelegramMessage::query()->whereKey($row->id)->update(['status' => TelegramMessageStatus::Pending, 'updated_at' => now()]);
    });

    expect(app(ResendTelegramMessage::class)->handle($row, $author))->toBeFalse();
    Bus::assertNotDispatched(DeliverBotMessage::class);
});

it('refuses resend when delivery cannot happen', function (array $state, bool $botOn, string $reason) {
    Bus::fake([DeliverBotMessage::class]);
    replyBotSwitch($botOn);
    $author = staffUser(Role::Admin);
    $chat = replyThread($state);
    $row = replyOwnFailed($chat, $author);

    expect(fn () => app(ResendTelegramMessage::class)->handle($row, $author))
        ->toThrow(fn (InboxRefused $refused) => expect($refused->reason)->toBe($reason));
    expect($row->fresh()->status)->toBe(TelegramMessageStatus::Failed);
    Bus::assertNotDispatched(DeliverBotMessage::class);
})->with([
    'banned' => [['banned_at' => now()], true, 'banned'],
    'blocked' => [['blocked_at' => now()], true, 'blocked'],
    'bot off' => [[], false, 'bot_off'],
    'unregistered' => [['registered_at' => null], true, 'unregistered'],
]);

it('shares the per-user limit with replies', function () {
    Bus::fake([DeliverBotMessage::class]);
    $author = replyManager();
    $chat = replyThread(manager: $author);
    $row = replyOwnFailed($chat, $author);

    foreach (range(1, SendTelegramReply::PER_MINUTE) as $i) {
        RateLimiter::hit(SendTelegramReply::LIMIT_KEY.$author->id, 60);
    }

    expect(fn () => app(ResendTelegramMessage::class)->handle($row, $author))
        ->toThrow(fn (InboxRefused $refused) => expect($refused->reason)->toBe('rate_limited'));
    expect($row->fresh()->status)->toBe(TelegramMessageStatus::Failed);
});

it('shows the buttons to the author and a duplicate warning only on a stuck row', function () {
    Bus::fake([DeliverBotMessage::class]);
    $author = replyManager();
    $chat = replyThread(manager: $author);
    $failed = replyOwnFailed($chat, $author);
    $stuck = TelegramMessage::factory()->for($chat)->outbound($author)->create(['status' => TelegramMessageStatus::Sending]);
    TelegramMessage::query()->whereKey($stuck->id)->update(['updated_at' => now()->subMinutes(30)]);

    $html = Livewire::actingAs($author)->test(Show::class, ['telegramUser' => $chat])->html();

    expect($html)->toContain('data-resend="'.$failed->id.'"')->toContain('data-resend="'.$stuck->id.'"');
    expect($html)->not->toMatch('/wire:confirm="[^"]*"[^>]*data-resend="'.$failed->id.'"/');
    expect($html)->toMatch('/wire:confirm="[^"]*"[^>]*data-resend="'.$stuck->id.'"/');
});

it('hides the buttons from another viewer of the thread', function () {
    $chat = replyThread();
    replyOwnFailed($chat, replyManager());

    Livewire::actingAs(replyManager())->test(Show::class, ['telegramUser' => $chat])->assertDontSeeHtml('data-resend=');
});

it('loads a resend target only through this thread and outbound rows', function () {
    Bus::fake([DeliverBotMessage::class]);
    $author = staffUser(Role::Admin);
    $chat = replyThread();
    $elsewhere = replyOwnFailed(replyThread(), $author);
    $inbound = TelegramMessage::factory()->for($chat)->create();

    $component = Livewire::actingAs($author)->test(Show::class, ['telegramUser' => $chat]);

    $component->call('resend', $elsewhere->id)->assertNotFound();
    $component = Livewire::actingAs($author)->test(Show::class, ['telegramUser' => $chat]);
    $component->call('resend', $inbound->id)->assertNotFound();

    expect($elsewhere->fresh()->status)->toBe(TelegramMessageStatus::Failed);
    Bus::assertNotDispatched(DeliverBotMessage::class);
});

it('resends through the screen', function () {
    Bus::fake([DeliverBotMessage::class]);
    $author = replyManager();
    $chat = replyThread(manager: $author);
    $row = replyOwnFailed($chat, $author);

    Livewire::actingAs($author)->test(Show::class, ['telegramUser' => $chat])
        ->call('resend', $row->id)
        ->assertDispatched('toast', type: 'success');

    Bus::assertDispatchedTimes(DeliverBotMessage::class, 1);
    expect($row->fresh()->status)->toBe(TelegramMessageStatus::Pending);
});

/**
 * Real Livewire update request calling sendReply; Livewire::test() would skip the persistent middleware.
 */
function replyUpdateRequest(User $user, TelegramUser $chat, bool $deactivate): TestResponse
{
    $html = test()->actingAs($user)->get(route('admin.telegram.chats.show', $chat))->assertOk()->getContent();
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

    $snapshot = collect($matches[1])
        ->map(fn (string $raw) => html_entity_decode($raw, ENT_QUOTES))
        ->first(fn (string $candidate) => array_key_exists('conversationId', json_decode($candidate, true)['data'] ?? []));
    expect($snapshot)->not->toBeNull();

    if ($deactivate) {
        $user->forceFill(['is_active' => false])->save();
    }

    return test()->withHeaders(['X-Livewire' => '1'])->postJson(app('livewire')->getUpdateUri(), [
        'components' => [['snapshot' => $snapshot, 'calls' => [['method' => 'sendReply', 'params' => [], 'metadata' => []]], 'updates' => ['reply' => 'real request']]],
    ]);
}

it('returns 403 to a real reply request after the manager is deactivated', function () {
    Bus::fake([DeliverBotMessage::class]);
    $user = replyManager();
    $chat = replyThread(manager: $user);

    replyUpdateRequest($user, $chat, deactivate: true)->assertForbidden();

    expect(TelegramMessage::query()->count())->toBe(0);
    Bus::assertNotDispatched(DeliverBotMessage::class);
});

it('accepts a real reply request from an active manager', function () {
    Bus::fake([DeliverBotMessage::class]);
    $user = replyManager();
    $chat = replyThread(manager: $user);

    replyUpdateRequest($user, $chat, deactivate: false)->assertOk();

    expect(TelegramMessage::query()->sole()->text)->toBe('real request');
    Bus::assertDispatchedTimes(DeliverBotMessage::class, 1);
});
