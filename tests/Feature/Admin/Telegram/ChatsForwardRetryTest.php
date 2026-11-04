<?php

use App\Actions\Telegram\RetryForward;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Enums\TelegramForwardStatus;
use App\Enums\TelegramMessageKind;
use App\Jobs\ForwardTelegramMedia;
use App\Livewire\Admin\Telegram\Chats\Show;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Models\User;
use App\Support\Telegram\Bot\BotSwitch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/**
 * Plan 21 A5: forward lines, retry and the not-configured banner (T21-16).
 */
beforeEach(function () {
    telegramBotConfigured();
    forwardRetryBot(true);
    Http::fake(['api.telegram.org/*' => function (Request $request) {
        return Http::response(['ok' => true, 'result' => str_ends_with($request->url(), '/getChat')
            ? ['id' => -1001111111111, 'type' => 'supergroup']
            : ['message_id' => 901]]);
    }]);
});

function forwardRetryBot(bool $on): void
{
    Setting::query()->updateOrCreate(['key' => BotSwitch::KEY], ['group' => SettingsArea::TelegramBot->group(), 'value' => $on]);
}

function forwardRetryManager(): User
{
    return staffUser(Role::SalesManager);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function forwardRetryThread(array $attributes = [], ?User $manager = null): TelegramUser
{
    $factory = TelegramUser::factory()->registered();

    return ($manager ? $factory->assigned($manager) : $factory)->create($attributes);
}

function forwardRetryFile(TelegramUser $chat, TelegramForwardStatus $status = TelegramForwardStatus::Failed): TelegramMessage
{
    return TelegramMessage::factory()->for($chat)->media(TelegramMessageKind::Document)->create([
        'text' => 'caption secret',
        'media' => ['file_name' => 'secret-name.pdf', 'mime_type' => 'application/pdf', 'file_size' => 100],
        'forward_status' => $status,
        'forward_error_code' => $status === TelegramForwardStatus::Failed ? 400 : null,
    ]);
}

it('lets any viewer retry, not only the owner, and refuses others', function (Role $role, bool $allowed) {
    Bus::fake([ForwardTelegramMedia::class]);
    $row = forwardRetryFile(forwardRetryThread());
    $actor = staffUser($role);

    if ($allowed) {
        expect(app(RetryForward::class)->handle($actor, $row))->toBeTrue();
        Bus::assertDispatchedTimes(ForwardTelegramMedia::class, 1);
    } else {
        expect(fn () => app(RetryForward::class)->handle($actor, $row))->toThrow(AuthorizationException::class);
        Bus::assertNotDispatched(ForwardTelegramMedia::class);
    }
})->with([
    'admin' => [Role::Admin, true],
    'sales manager' => [Role::SalesManager, true],
    'content manager' => [Role::ContentManager, false],
    'service engineer' => [Role::ServiceEngineer, false],
]);

it('refuses a manager on another manager\'s thread by direct call', function () {
    Bus::fake([ForwardTelegramMedia::class]);
    $user = forwardRetryManager();
    $owner = forwardRetryManager();
    $chat = forwardRetryThread(manager: $owner);
    $row = forwardRetryFile($chat);

    expect(fn () => app(RetryForward::class)->handle($user, $row))->toThrow(AuthorizationException::class);

    $view = Livewire::actingAs($owner)->test(Show::class, ['telegramUser' => $chat]);
    $chat->forceFill(['manager_id' => $user->id])->save();
    $view->call('retryForward', $row->id)->assertForbidden();

    Bus::assertNotDispatched(ForwardTelegramMedia::class);
    expect(SettingsAudit::query()->count())->toBe(0);
});

it('writes an audit row with no text or file name (T21-16)', function () {
    Bus::fake([ForwardTelegramMedia::class]);
    $actor = staffUser(Role::Admin);
    $chat = forwardRetryThread();
    $row = forwardRetryFile($chat);

    app(RetryForward::class)->handle($actor, $row, '10.0.0.3');

    $audit = SettingsAudit::query()->sole();
    expect($audit->action)->toBe(AuditAction::RetryForward)
        ->and($audit->area)->toBe(SettingsArea::TelegramBot)
        ->and($audit->key)->toBe('telegram.conversation.'.$chat->id)
        ->and($audit->user_id)->toBe($actor->id)
        ->and($audit->ip)->toBe('10.0.0.3');

    expect(json_encode($audit->toArray()))->not->toContain('secret-name')->not->toContain('caption secret');
});

it('writes no audit row for a refused retry', function () {
    Bus::fake([ForwardTelegramMedia::class]);
    $row = forwardRetryFile(forwardRetryThread(), TelegramForwardStatus::Forwarded);

    expect(app(RetryForward::class)->handle(staffUser(Role::Admin), $row))->toBeFalse()
        ->and(SettingsAudit::query()->count())->toBe(0);
});

it('caps retries per person, across messages (T21-16)', function () {
    Bus::fake([ForwardTelegramMedia::class]);
    $user = forwardRetryManager();
    $other = staffUser(Role::Admin);
    $chat = forwardRetryThread();

    foreach (range(1, RetryForward::PER_USER_PER_HOUR) as $i) {
        RateLimiter::hit(RetryForward::PER_USER_KEY.$user->id, 3600);
    }

    $row = forwardRetryFile($chat);

    expect(app(RetryForward::class)->handle($user, $row))->toBeFalse();
    Bus::assertNotDispatched(ForwardTelegramMedia::class);
    expect(SettingsAudit::query()->count())->toBe(0);

    $second = forwardRetryFile($chat);
    expect(app(RetryForward::class)->handle($other, $second))->toBeTrue();
});

it('keeps the per-message limit', function () {
    Bus::fake([ForwardTelegramMedia::class]);
    $row = forwardRetryFile(forwardRetryThread());
    $admin = staffUser(Role::Admin);

    expect(app(RetryForward::class)->handle($admin, $row))->toBeTrue()
        ->and(app(RetryForward::class)->handle($admin, $row))->toBeFalse();
    Bus::assertDispatchedTimes(ForwardTelegramMedia::class, 1);
    expect(SettingsAudit::query()->count())->toBe(1);
});

it('keeps the caps: a retry over the conversation cap stays limited and copies nothing', function () {
    $chat = forwardRetryThread();
    $row = forwardRetryFile($chat, TelegramForwardStatus::Limited);

    foreach (range(1, ForwardTelegramMedia::CONVERSATION_PER_HOUR) as $i) {
        RateLimiter::hit(ForwardTelegramMedia::conversationKey($chat->id), 3600);
    }

    Livewire::actingAs(staffUser(Role::Admin))->test(Show::class, ['telegramUser' => $chat])
        ->call('retryForward', $row->id)
        ->assertDispatched('toast', type: 'success');

    expect($row->fresh()->forward_status)->toBe(TelegramForwardStatus::Limited);
    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/copyMessage'));
});

it('consumes the shared daily cap on a successful retry', function () {
    $chat = forwardRetryThread();
    $row = forwardRetryFile($chat);

    Livewire::actingAs(staffUser(Role::Admin))->test(Show::class, ['telegramUser' => $chat])->call('retryForward', $row->id);

    expect($row->fresh()->forward_status)->toBe(TelegramForwardStatus::Forwarded)
        ->and(RateLimiter::attempts(ForwardTelegramMedia::DAILY_KEY))->toBe(1);
});

it('refuses a retry when the bot is off or the user is banned', function (bool $botOn, array $attributes) {
    Bus::fake([ForwardTelegramMedia::class]);
    forwardRetryBot($botOn);
    $chat = forwardRetryThread($attributes);
    $row = forwardRetryFile($chat);

    expect(app(RetryForward::class)->handle(staffUser(Role::Admin), $row))->toBeFalse()
        ->and(RateLimiter::attempts(RetryForward::PER_MINUTE_KEY.$row->id))->toBe(0)
        ->and(SettingsAudit::query()->count())->toBe(0);
    Bus::assertNotDispatched(ForwardTelegramMedia::class);

    Livewire::actingAs(staffUser(Role::Admin))->test(Show::class, ['telegramUser' => $chat])
        ->assertDontSeeHtml('data-retry-forward')
        ->call('retryForward', $row->id)
        ->assertDispatched('toast', type: 'error');
})->with([
    'bot off' => [false, []],
    'banned' => [true, ['banned_at' => now()]],
]);

it('loads a retry target only through this thread and inbound rows', function () {
    Bus::fake([ForwardTelegramMedia::class]);
    $admin = staffUser(Role::Admin);
    $chat = forwardRetryThread();
    $elsewhere = forwardRetryFile(forwardRetryThread());
    $outbound = TelegramMessage::factory()->for($chat)->outbound($admin)->create();

    Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat])->call('retryForward', $elsewhere->id)->assertNotFound();
    Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat])->call('retryForward', $outbound->id)->assertNotFound();

    Bus::assertNotDispatched(ForwardTelegramMedia::class);
});

it('renders a forward line per status, with a retry button only where it can work', function (TelegramForwardStatus $status, bool $button) {
    $chat = forwardRetryThread();
    $row = forwardRetryFile($chat, $status);

    $component = Livewire::actingAs(forwardRetryManager())->test(Show::class, ['telegramUser' => $chat]);
    $component->assertSeeHtml('data-forward-line="'.$status->value.'"')->assertSee(__('telegram_inbox.forward_lines.'.$status->value));

    $button
        ? $component->assertSeeHtml('data-retry-forward="'.$row->id.'"')
        : $component->assertDontSeeHtml('data-retry-forward');
})->with([
    'failed' => [TelegramForwardStatus::Failed, true],
    'not configured' => [TelegramForwardStatus::NotConfigured, true],
    'limited' => [TelegramForwardStatus::Limited, true],
    'fresh pending' => [TelegramForwardStatus::Pending, false],
    'fresh sending' => [TelegramForwardStatus::Sending, false],
    'forwarded' => [TelegramForwardStatus::Forwarded, false],
]);

it('offers retry on a stuck claim', function () {
    $chat = forwardRetryThread();
    $row = forwardRetryFile($chat, TelegramForwardStatus::Sending);
    TelegramMessage::query()->whereKey($row->id)->update(['updated_at' => now()->subMinutes(TelegramMessage::STUCK_MINUTES + 1)]);

    Livewire::actingAs(forwardRetryManager())->test(Show::class, ['telegramUser' => $chat])
        ->assertSeeHtml('data-retry-forward="'.$row->id.'"')
        ->assertSeeHtml('data-stuck');
});

it('has every forward line and the new audit label in all locales', function (string $locale) {
    foreach (TelegramForwardStatus::cases() as $status) {
        expect(__('telegram_inbox.forward_lines.'.$status->value, [], $locale))->not->toStartWith('telegram_inbox.');
    }

    expect(__('enums.audit_action.retry_forward', [], $locale))->not->toStartWith('enums.');
})->with(['uz', 'ru', 'en']);

it('shows the not-configured banner when no usable team chat is set', function (?array $secrets, bool $banner) {
    if ($secrets !== null) {
        telegramBotConfigured($secrets);
    }

    $component = Livewire::actingAs(staffUser(Role::Admin))->test(Show::class, ['telegramUser' => forwardRetryThread()]);

    $banner
        ? $component->assertSeeHtml('data-banner="forward-not-configured"')
        : $component->assertDontSeeHtml('data-banner="forward-not-configured"');
})->with([
    'sales chat set' => [null, false],
    'no chat' => [['services.telegram.chats.sales' => ''], true],
    'public username' => [['services.telegram.chats.sales' => '@public_chat'], true],
]);

it('never renders the file id or Telegram ids on the forward line and keeps polling', function () {
    $chat = forwardRetryThread(['telegram_id' => 7700123456]);
    $row = forwardRetryFile($chat);
    TelegramMessage::query()->whereKey($row->id)->update(['forwarded_message_id' => 556677889, 'telegram_message_id' => 918273645]);

    $component = Livewire::actingAs(staffUser(Role::Admin))->test(Show::class, ['telegramUser' => $chat]);
    $component->assertSeeHtml('wire:poll.10s.visible')->call('refreshThread')->assertOk();

    expect($component->html().json_encode($component->snapshot))
        ->not->toContain('7700123456')->not->toContain('556677889')->not->toContain('918273645')->not->toContain('file_id');
});
