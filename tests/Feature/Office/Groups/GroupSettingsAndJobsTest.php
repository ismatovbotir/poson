<?php

use App\Actions\Office\SaveOfficeSettings;
use App\Actions\Office\UpsertGroupMember;
use App\Actions\Telegram\ConfigureTelegramWebhook;
use App\Enums\GroupMemberRole;
use App\Enums\Role;
use App\Jobs\FetchGroupAdministrators;
use App\Livewire\Admin\Office\Settings;
use App\Livewire\Admin\Telegram\Bot;
use App\Models\Setting;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupMember;
use App\Services\Telegram\TelegramClient;
use App\Support\Office\OfficeSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

require_once __DIR__.'/../../../Helpers/Office.php';
require_once __DIR__.'/../../../Helpers/Groups.php';

/*
| Plan 44 T4: the groups switch, the administrator job, the Status warning
| and the "no send to a group" arch test (OG-1).
*/

beforeEach(function () {
    Http::preventStrayRequests();
});

it('keeps the groups switch off by default, independent of the bot switch, and saves it for an Admin only', function () {
    expect(OfficeSettings::groupsStored())->toBeFalse()->and(OfficeSettings::groupsEnabled())->toBeFalse();

    Livewire::actingAs(staffUser())->test(Settings::class)
        ->assertSet('groupsEnabled', false)
        ->set('groupsEnabled', true)
        ->call('save');

    expect(Setting::query()->where('key', 'office.groups_enabled')->value('value'))->toBeTrue()
        ->and(OfficeSettings::groupsStored())->toBeTrue()
        // The office ingest switch is still off, so groups are not processed.
        ->and(OfficeSettings::groupsEnabled())->toBeFalse();

    officeSettings(['ingest_enabled' => true]);

    expect(OfficeSettings::groupsEnabled())->toBeTrue();
});

it('refuses the switch for everyone but an Admin', function (Role $role) {
    expect(fn () => app(SaveOfficeSettings::class)->handle(staffUser($role), ['groups_enabled' => true], null))->toThrow(AuthorizationException::class);
})->with([Role::ContentManager, Role::SalesManager, Role::ServiceEngineer]);

it('lists nine update types and keeps telegram:poll on the same constant', function () {
    expect(ConfigureTelegramWebhook::ALLOWED_UPDATES)->toHaveCount(9)
        ->toContain('edited_message', 'chat_member', 'message', 'callback_query', 'my_chat_member')
        ->and(file_get_contents(base_path('app/Console/Commands/TelegramPoll.php')))->toContain('ConfigureTelegramWebhook::ALLOWED_UPDATES');
});

it('flags a webhook that lacks the two group types', function () {
    $old = ['message', 'callback_query', 'my_chat_member', 'business_connection', 'business_message', 'edited_business_message', 'deleted_business_messages'];

    expect(ConfigureTelegramWebhook::hasAllowedUpdates(['allowed_updates' => $old]))->toBeFalse()
        ->and(ConfigureTelegramWebhook::hasAllowedUpdates(['allowed_updates' => ConfigureTelegramWebhook::ALLOWED_UPDATES]))->toBeTrue();
});

it('warns on the bot page to turn off Allow Groups while the groups switch is on', function (?bool $canJoin, bool $switch, bool $shown) {
    telegramBotConfigured();
    officeSettings(['groups_enabled' => $switch]);

    Http::fake([
        'api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => array_filter(['id' => 9990000600, 'username' => 'fake_poson_bot', 'can_join_groups' => $canJoin], fn ($value) => $value !== null)]),
        'api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => ConfigureTelegramWebhook::expectedUrl(), 'allowed_updates' => ConfigureTelegramWebhook::ALLOWED_UPDATES]]),
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
    ]);

    $test = Livewire::actingAs(staffUser())->test(Bot::class)->call('refresh');

    $shown ? $test->assertSeeHtml('data-allow-groups-warning') : $test->assertDontSeeHtml('data-allow-groups-warning');
})->with([
    'groups on, bot can join' => [true, true, true],
    'groups on, unknown (runbook reminder)' => [null, true, true],
    'groups on, joining off' => [false, true, false],
    'groups off' => [true, false, false],
]);

it('fetches the administrators of a tracked group as members, once in 10 minutes', function () {
    telegramBotConfigured();
    Cache::flush();
    groupsReady();
    $group = groupTracked();

    Http::fake(['api.telegram.org/*/getChatAdministrators' => Http::response(['ok' => true, 'result' => [
        ['status' => 'creator', 'user' => ['id' => GROUP_STAFF_ID, 'is_bot' => false, 'first_name' => 'Fake Staff', 'username' => 'fake_staff']],
        ['status' => 'administrator', 'user' => ['id' => 9990000600, 'is_bot' => true, 'first_name' => 'Fake Poson Bot', 'username' => 'fake_poson_bot']],
        ['status' => 'administrator', 'user' => ['id' => 'x']],
    ]])]);

    expect(FetchGroupAdministrators::dispatchFor($group))->toBeTrue()
        ->and(FetchGroupAdministrators::dispatchFor($group))->toBeFalse();

    $calls = collect(telegramApiCalls());

    expect($calls->pluck('method')->all())->toBe(['getChatAdministrators'])
        ->and(TelegramGroupMember::query()->where('telegram_id', GROUP_STAFF_ID)->sole()->member_status)->toBe('creator')
        ->and(TelegramGroupMember::query()->where('telegram_id', 9990000600)->sole()->role)->toBe(GroupMemberRole::Bot)
        ->and(TelegramGroupMember::query()->count())->toBe(2);
});

it('never fetches administrators for an untracked or ignored group', function (string $state) {
    Queue::fake();
    $group = TelegramGroup::factory()->{$state}()->create();

    expect(FetchGroupAdministrators::dispatchFor($group))->toBeFalse();
    Queue::assertNothingPushed();

    // And the job itself refuses (a stale queue entry).
    Http::fake();
    (new FetchGroupAdministrators($group->id))->handle(app(TelegramClient::class), app(UpsertGroupMember::class));
    Http::assertNothingSent();
})->with(['untracked', 'ignored']);

it('gives TelegramClient no new call except getChatAdministrators and sends nothing to a group (OG-1 arch)', function () {
    $methods = collect((new ReflectionClass(TelegramClient::class))->getMethods(ReflectionMethod::IS_PUBLIC))->pluck('name')->sort()->values()->all();

    // No leave / pin / ban / promote call exists, and the only group call is the read one.
    foreach (['leaveChat', 'pinChatMessage', 'unpinChatMessage', 'banChatMember', 'promoteChatMember', 'exportChatInviteLink', 'setChatTitle'] as $forbidden) {
        expect($methods)->not->toContain($forbidden);
    }

    expect($methods)->toContain('getChatAdministrators');

    // Group code never reaches an outbound send method.
    $groupFiles = array_merge(
        [app_path('Actions/Office/HandleGroupUpdate.php'), app_path('Actions/Office/UpsertGroupMember.php'), app_path('Jobs/FetchGroupAdministrators.php'), app_path('Support/Telegram/Bot/GroupUpdate.php')],
    );
    $sends = '/->(sendMessage|sendWebAppMessage|sendDocument|sendChatAction|copyMessage|deleteMessage|editMessageReplyMarkup|answerCallbackQuery|setChatMenuButton|leaveChat|pinChatMessage)\(|SendBotPrompt|SendBotAnswer|DeliverBotMessage|SendTelegramMessage|AnswerBotCallback/';

    foreach ($groupFiles as $file) {
        expect(preg_match($sends, (string) file_get_contents($file)))->toBe(0, basename($file));
    }

    // And no file that sends touches a group model.
    $senders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $code = (string) file_get_contents($file->getPathname());

        if (preg_match('/->(sendMessage|sendWebAppMessage|sendDocument|copyMessage|deleteMessage)\(/', $code) === 1 && preg_match('/TelegramGroup|GroupUpdate|telegram_groups/', $code) === 1) {
            $senders[] = str_replace(app_path().'/', '', $file->getPathname());
        }
    }

    expect($senders)->toBe([]);
});

it('M1: the administrator job makes no HTTP call while groups are off, for an untracked group or after the bot left', function (string $case) {
    telegramBotConfigured();
    Cache::flush();
    groupsReady();
    Http::fake();

    $group = match ($case) {
        'switch off' => tap(groupTracked(), fn () => officeSettings(['groups_enabled' => false])),
        'ingest off' => tap(groupTracked(), fn () => officeSettings(['ingest_enabled' => false])),
        'kicked' => groupTracked(attributes: ['bot_status' => 'kicked']),
        'left' => groupTracked(attributes: ['bot_status' => 'left']),
        default => TelegramGroup::factory()->create(),
    };

    (new FetchGroupAdministrators($group->id))->handle(app(TelegramClient::class), app(UpsertGroupMember::class));

    Http::assertNothingSent();
})->with(['switch off', 'ingest off', 'kicked', 'left', 'untracked']);

it('keeps the 10-minute throttle free when the surrounding transaction rolls back', function () {
    Queue::fake();
    Cache::flush();
    $group = groupTracked();

    try {
        DB::transaction(function () use ($group): void {
            FetchGroupAdministrators::dispatchFor($group);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(Cache::has('office:group-admins:'.$group->id))->toBeFalse();
    Queue::assertNothingPushed();
    expect(FetchGroupAdministrators::dispatchFor($group))->toBeTrue();
    Queue::assertPushed(FetchGroupAdministrators::class, 1);
});

it('says in all three languages that group reading also needs the ingest switch', function (string $locale, string $needle) {
    expect(__('office.settings.groups_enabled_hint', [], $locale))->toContain($needle);
})->with([['en', 'ingest'], ['ru', 'ingest'], ['uz', 'ingest']]);
