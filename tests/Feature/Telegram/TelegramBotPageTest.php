<?php

use App\Actions\Telegram\CheckTelegramWebhook;
use App\Actions\Telegram\SetBotEnabled;
use App\Actions\Telegram\TelegramBotException;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Livewire\Admin\Telegram\Bot;
use App\Models\MonitoringState;
use App\Models\Page;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Support\Admin\Menu;
use App\Support\Telegram\Bot\BotChecklist;
use App\Support\Telegram\Bot\BotSwitch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Plan 14 v2 B2: /admin/telegram/bot (telegram.bot, Admin only), TG-37.
 */
const TG_PAGE_URL = 'https://poson.example.uz/api/telegram/webhook';

/**
 * Telegram answers: getMe, getWebhookInfo (url = $webhookUrl) and ok for writes.
 */
function botPageTelegram(string $webhookUrl = TG_PAGE_URL): void
{
    Http::fake(['api.telegram.org/*' => function ($request) use ($webhookUrl) {
        return match (basename(parse_url($request->url(), PHP_URL_PATH))) {
            'getMe' => Http::response(['ok' => true, 'result' => ['id' => 123456789, 'is_bot' => true, 'username' => 'poson_test_bot', 'can_read_all_group_messages' => false]]),
            'getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => $webhookUrl, 'pending_update_count' => 0]]),
            'getChat' => Http::response(['ok' => true, 'result' => ['id' => -1001111111111, 'type' => 'supergroup']]),
            default => Http::response(['ok' => true, 'result' => true]),
        };
    }]);
}

function privacyPage(bool $active = true, bool $needsReview = false): void
{
    Page::factory()->create(['slug' => 'privacy', 'is_active' => $active, 'needs_review' => $needsReview]);
}

beforeEach(function () {
    telegramBotConfigured();
});

it('loads the page without calling Telegram, then refresh shows getMe and the checklist', function () {
    botPageTelegram();
    privacyPage();
    $admin = staffUser();

    $this->actingAs($admin)
        ->get(route('admin.telegram.bot'))
        ->assertOk()
        ->assertSee('wire:init="refresh"', false)
        ->assertSee('data-loading-skeleton', false)
        ->assertSee('data-bot-state="disabled"', false)
        ->assertSee('data-check="privacy" data-ok="1"', false)
        ->assertSee('data-check="webhook" data-ok=""', false);

    expect(telegramApiCalls())->toBe([]);

    Livewire::actingAs($admin)->test(Bot::class)
        ->call('refresh')
        ->assertSet('loaded', true)
        ->assertSet('checklist.webhook', true)
        ->assertSee('@poson_test_bot')
        ->assertSee('data-webhook-state="matches"', false)
        ->assertDontSee(TG_BOT_TOKEN)
        ->assertDontSee(TG_WEBHOOK_SECRET);

    expect(collect(telegramApiCalls())->pluck('method')->all())->toBe(['getMe', 'getWebhookInfo', 'getChat']);
});

it('limits refreshes to 10 per minute per user', function () {
    botPageTelegram();
    $component = Livewire::actingAs(staffUser())->test(Bot::class);

    foreach (range(1, 10) as $ignored) {
        $component->call('refresh')->assertNotDispatched('toast');
    }

    $calls = count(telegramApiCalls());
    $component->call('refresh')->assertDispatched('toast', type: 'error');

    expect(count(telegramApiCalls()))->toBe($calls)->toBe(21);
});

it('shows a banner for an open token problem', function () {
    botPageTelegram();
    MonitoringState::query()->create(['key' => CheckTelegramWebhook::TOKEN_KEY, 'problem_since' => now()]);

    $this->actingAs(staffUser())->get(route('admin.telegram.bot'))
        ->assertOk()
        ->assertSee('data-problem="telegram_token"', false);
});

it('creates the switch row safely when another request inserted it first', function () {
    botPageTelegram();
    privacyPage();
    $admin = staffUser();
    $raced = false;

    // Another request inserts the row right after this one saw it missing.
    DB::listen(function ($query) use (&$raced): void {
        if (! $raced && str_contains($query->sql, 'exists') && str_contains($query->sql, '"settings"')) {
            $raced = true;
            DB::table('settings')->insert(['key' => BotSwitch::KEY, 'group' => 'telegram', 'value' => 'false', 'created_at' => now(), 'updated_at' => now()]);
        }
    });

    expect(app(SetBotEnabled::class)->enable($admin, null))->toBeTrue()
        ->and($raced)->toBeTrue()
        ->and(BotSwitch::isEnabled())->toBeTrue()
        ->and(Setting::query()->where('key', BotSwitch::KEY)->count())->toBe(1);
});

it('forbids the page, every action and the menu item to other roles', function (Role $role) {
    botPageTelegram();
    $user = staffUser($role);

    $this->actingAs($user)->get(route('admin.telegram.bot'))->assertForbidden();
    Livewire::actingAs($user)->test(Bot::class)->assertForbidden();

    expect(fn () => app(SetBotEnabled::class)->disable($user, null))->toThrow(AuthorizationException::class)
        ->and(collect(Menu::for($user))->flatMap(fn ($group) => $group->items)->pluck('key')->all())->not->toContain('telegram-bot');
})->with([
    'content manager' => [Role::ContentManager],
    'sales manager' => [Role::SalesManager],
    'service engineer' => [Role::ServiceEngineer],
]);

it('requires a recent password for every write and changes nothing without it', function (string $method) {
    botPageTelegram();
    privacyPage();

    Livewire::actingAs(staffUser())->test(Bot::class)
        ->call($method)
        ->assertRedirect(route('admin.confirm-password'));

    expect(SettingsAudit::query()->count())->toBe(0)
        ->and(BotSwitch::isEnabled())->toBeFalse()
        ->and(collect(telegramApiCalls())->pluck('method')->intersect(['setWebhook', 'deleteWebhook'])->all())->toBe([]);
})->with(['enable', 'disable', 'setWebhook', 'deleteWebhook']);

it('refuses the confirmation once it is older than 15 minutes', function () {
    botPageTelegram();
    privacyPage();
    confirmAdminPassword(16 * 60);

    Livewire::actingAs(staffUser())->test(Bot::class)->call('enable')->assertRedirect(route('admin.confirm-password'));

    expect(BotSwitch::isEnabled())->toBeFalse();
});

it('enables and disables the bot with an audit row each time', function () {
    botPageTelegram();
    privacyPage();
    confirmAdminPassword();
    $admin = staffUser();

    Livewire::actingAs($admin)->test(Bot::class)
        ->call('enable')
        ->assertDispatched('toast', type: 'success');

    expect(BotSwitch::isEnabled())->toBeTrue();

    Livewire::actingAs($admin)->test(Bot::class)->call('disable')->assertDispatched('toast', type: 'success');

    expect(BotSwitch::isEnabled())->toBeFalse()
        ->and(SettingsAudit::query()->orderBy('id')->get(['area', 'key', 'action', 'user_id'])->map(fn ($audit) => [$audit->area, $audit->key, $audit->action, $audit->user_id])->all())
        ->toBe([
            [SettingsArea::TelegramBot, BotSwitch::KEY, AuditAction::Enabled, $admin->id],
            [SettingsArea::TelegramBot, BotSwitch::KEY, AuditAction::Disabled, $admin->id],
        ])
        ->and(Setting::query()->where('key', BotSwitch::KEY)->sole()->group)->toBe('telegram');
});

it('refuses to enable when a checklist item fails, re-checked on the server', function (Closure $break, string $item) {
    botPageTelegram();
    privacyPage();
    confirmAdminPassword();
    $admin = staffUser();

    // The screen was loaded while everything passed …
    $component = Livewire::actingAs($admin)->test(Bot::class)->assertSet('checklist.'.$item, true);

    // … then something broke: the enable call must evaluate again.
    $break();

    $component->call('enable')->assertDispatched('toast', type: 'error');

    expect(BotSwitch::isEnabled())->toBeFalse()
        ->and(SettingsAudit::query()->count())->toBe(0);
})->with([
    'privacy page needs review' => [fn () => Page::query()->where('slug', 'privacy')->update(['needs_review' => true]), 'privacy'],
    'privacy page inactive' => [fn () => Page::query()->where('slug', 'privacy')->update(['is_active' => false]), 'privacy'],
    'no sales or inbox chat' => [fn () => telegramBotConfigured(['services.telegram.chats.sales' => null]), 'chat'],
    'no webhook secret' => [fn () => telegramBotConfigured(['services.telegram.webhook_secret' => null]), 'webhook_secret'],
    'http app url' => [fn () => config(['app.url' => 'http://poson.example.uz']), 'app_url'],
]);

it('refuses to enable when Telegram points the webhook elsewhere', function () {
    botPageTelegram('https://attacker.example/hook');
    privacyPage();
    $admin = staffUser();

    expect(fn () => app(SetBotEnabled::class)->enable($admin, null))
        ->toThrow(fn (TelegramBotException $exception) => expect($exception->failing)->toContain('webhook'));

    expect(BotSwitch::isEnabled())->toBeFalse();
});

it('accepts the inbox chat instead of the sales chat', function () {
    botPageTelegram();
    privacyPage();
    telegramBotConfigured(['services.telegram.chats.sales' => null, 'services.telegram.chats.inbox' => '-1002222222222']);

    expect(app(SetBotEnabled::class)->enable(staffUser(), null))->toBeTrue();
});

it('disables with the password only, even when the checklist fails', function () {
    botPageTelegram('');
    confirmAdminPassword();
    Setting::query()->create(['key' => BotSwitch::KEY, 'group' => 'telegram', 'value' => true]);

    Livewire::actingAs(staffUser())->test(Bot::class)
        ->assertSet('checklist.privacy', false)
        ->call('disable')
        ->assertDispatched('toast', type: 'success');

    expect(BotSwitch::isEnabled())->toBeFalse()
        ->and(SettingsAudit::query()->sole()->action)->toBe(AuditAction::Disabled);
});

it('sets and deletes the webhook from the page, audited', function () {
    botPageTelegram();
    confirmAdminPassword();

    Livewire::actingAs(staffUser())->test(Bot::class)
        ->call('setWebhook')
        ->call('deleteWebhook');

    expect(collect(telegramApiCalls())->pluck('method')->intersect(['setWebhook', 'deleteWebhook'])->countBy()->all())
        ->toBe(['setWebhook' => 1, 'deleteWebhook' => 1])
        ->and(SettingsAudit::query()->orderBy('id')->pluck('action')->all())
        ->toBe([AuditAction::WebhookSet, AuditAction::WebhookDeleted]);
});

it('locks the displayed state against tampering', function () {
    botPageTelegram();

    Livewire::actingAs(staffUser())->test(Bot::class)
        ->set('checklist', ['privacy' => true]);
})->throws(CannotUpdateLockedPropertyException::class);

it('no longer exposes the removed setCommands action or its button', function () {
    botPageTelegram();
    confirmAdminPassword();

    $component = Livewire::actingAs(staffUser())->test(Bot::class);

    expect(fn () => $component->call('setCommands'))->toThrow(MethodNotFoundException::class);

    $component->assertSeeHtml('data-action="set-webhook"')
        ->assertDontSeeHtml('data-action="set-commands"');

    expect(SettingsAudit::query()->count())->toBe(0)
        ->and(collect(telegramApiCalls())->pluck('method')->contains('setMyCommands'))->toBeFalse();
});

it('still renders a legacy telegram.commands audit row', function () {
    botPageTelegram();
    SettingsAudit::factory()->create([
        'area' => SettingsArea::TelegramBot,
        'key' => 'telegram.commands',
        'action' => AuditAction::Updated,
    ]);

    Livewire::actingAs(staffUser())->test(Bot::class)->assertSee('telegram.commands');
});

/**
 * Plan 21 E3: webhook is fine, getChat answers with $chat (a response or a Closure).
 */
function botPageChatAnswers(mixed $chat): void
{
    Http::fake(['api.telegram.org/*' => fn ($request) => match (basename(parse_url($request->url(), PHP_URL_PATH))) {
        'getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => TG_PAGE_URL, 'pending_update_count' => 0]]),
        'getChat' => $chat instanceof Closure ? $chat() : $chat,
        default => Http::response(['ok' => true, 'result' => true]),
    }]);
}

it('refuses to enable a refused group chat', function (array $chat) {
    privacyPage();
    botPageChatAnswers(Http::response(['ok' => true, 'result' => $chat]));

    expect(fn () => app(SetBotEnabled::class)->enable(staffUser(), null))
        ->toThrow(fn (TelegramBotException $exception) => expect($exception->reason)->toBe('not_ready')->and($exception->failing)->toBe(['chat']));

    expect(BotSwitch::isEnabled())->toBeFalse();
})->with([
    'public supergroup' => [['id' => -1001111111111, 'type' => 'supergroup', 'username' => 'public_group']],
    'channel' => [['id' => -1001111111111, 'type' => 'channel']],
]);

it('refuses to enable on a getChat 403', function () {
    privacyPage();
    botPageChatAnswers(Http::response(['ok' => false, 'error_code' => 403], 403));

    expect(fn () => app(SetBotEnabled::class)->enable(staffUser(), null))
        ->toThrow(fn (TelegramBotException $exception) => expect($exception->reason)->toBe('not_ready'));
});

it('refuses with try again on a 5xx or a timeout and caches nothing', function (Closure $answer) {
    privacyPage();
    $recovered = false;
    botPageChatAnswers(function () use (&$recovered, $answer) {
        return $recovered
            ? Http::response(['ok' => true, 'result' => ['id' => -1001111111111, 'type' => 'supergroup']])
            : $answer();
    });

    expect(fn () => app(SetBotEnabled::class)->enable(staffUser(), null))
        ->toThrow(fn (TelegramBotException $exception) => expect($exception->reason)->toBe('chat_unavailable')->and($exception->translated())->toBe(__('admin.telegram_bot.errors.chat_unavailable')));

    expect(BotSwitch::isEnabled())->toBeFalse();

    // Nothing was cached: once Telegram recovers, the same chat passes.
    $recovered = true;

    expect(app(SetBotEnabled::class)->enable(staffUser(), null))->toBeTrue();
})->with([
    '503' => [fn () => Http::response(['ok' => false], 503)],
    'timeout' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out after 3000 milliseconds')],
]);

it('bounds the chat check by a 3 second timeout', function () {
    expect(BotChecklist::CHAT_TIMEOUT)->toBe(3);
});

it('accepts a chat pass cached for 10 minutes without calling Telegram again', function () {
    privacyPage();
    botPageTelegram();
    app(SetBotEnabled::class)->enable(staffUser(), null);
    app(SetBotEnabled::class)->disable(staffUser(), null);
    botPageChatAnswers(Http::response(['ok' => false], 500));

    expect(app(SetBotEnabled::class)->enable(staffUser(), null))->toBeTrue()
        ->and(collect(telegramApiCalls())->pluck('method')->contains('getChat'))->toBeFalse();
});

it('shows the chat verdict and a check now button that reuses the status check', function () {
    botPageTelegram();
    $admin = staffUser();

    Livewire::actingAs($admin)->test(Bot::class)
        ->assertSeeHtml('data-chat-verdict="unknown"')
        ->call('checkChat')
        ->assertDispatched('toast', type: 'success')
        ->assertSeeHtml('data-chat-verdict="pass"')
        ->call('checkChat')
        ->assertDispatched('toast', type: 'error');
});

it('shows a failing chat verdict from the check', function () {
    botPageChatAnswers(Http::response(['ok' => true, 'result' => ['id' => -1001111111111, 'type' => 'supergroup', 'username' => 'public_group']]));

    Livewire::actingAs(staffUser())->test(Bot::class)
        ->call('checkChat')
        ->assertSeeHtml('data-chat-verdict="fail"');
});

it('forbids the chat check for non-admins', function () {
    Livewire::actingAs(staffUser(Role::ContentManager))->test(Bot::class)->assertForbidden();
});
