<?php

use App\Actions\Telegram\DeleteConversation;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Enums\TelegramMessageKind;
use App\Enums\TelegramMessageStatus;
use App\Enums\TelegramUserState;
use App\Events\Telegram\TelegramInboxMessage;
use App\Jobs\DeleteForwardedCopies;
use App\Jobs\DeliverBotMessage;
use App\Jobs\ForwardTelegramMedia;
use App\Jobs\SendTelegramMessage;
use App\Listeners\SendTelegramInboxAlert;
use App\Livewire\Admin\Customers\Show as CustomerShow;
use App\Livewire\Admin\Leads\Show as LeadShow;
use App\Livewire\Admin\Telegram\Chats\Show;
use App\Livewire\Admin\Telegram\Status;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Support\Phone;
use App\Support\Telegram\Bot\BotSwitch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * Plan 21 A7, B4: abuse cases for the Admin chat delete, the copy removal job
 * and the Customer / Lead Telegram blocks. Happy paths live in
 * tests/Feature/Admin/Telegram/ChatsDeleteTest.php, ChatsCrmBlocksTest.php and
 * tests/Feature/Telegram/DeleteForwardedCopiesTest.php. Bot API faked, test DB only.
 *
 * Not repeated, already covered:
 *  - a chat deleted on another tab redirects with a notice and a real poll renders nothing:
 *    ChatsDeleteTest "redirects a mounted tab with a notice when the chat was deleted, with no error"
 *    and "renders nothing for a real poll after the chat was deleted";
 *  - inbox audit rows without IP, other areas not listed: ChatsDeleteTest "lists inbox audit rows on the status page…"
 *    and "keeps inbox rows out of the Bot and Integrations lists";
 *  - another manager's row hidden by name on the Customer block:
 *    ChatsCrmBlocksTest "shows another manager no thread link and no identity on the Customer block".
 */
const TDEL_CHAT = -1002222222222;

beforeEach(function () {
    telegramBotConfigured();
    Setting::query()->updateOrCreate(['key' => BotSwitch::KEY], ['group' => SettingsArea::TelegramBot->group(), 'value' => true]);
    SymfonyRequest::setTrustedHosts([]);
});

afterEach(function () {
    SymfonyRequest::setTrustedHosts([]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function tdelThread(array $attributes = [], ?User $manager = null): TelegramUser
{
    $factory = TelegramUser::factory()->registered();

    return ($manager ? $factory->assigned($manager) : $factory)->create($attributes + [
        'username' => null,
        'first_name' => 'Fname',
        'last_name' => 'Lname',
    ]);
}

function tdelCopyRow(TelegramUser $chat, int $chatId, int $messageId, int $ageHours = 1): TelegramMessage
{
    $row = TelegramMessage::factory()->for($chat)->create();
    $row->forceFill(['forwarded_chat_id' => $chatId, 'forwarded_message_id' => $messageId, 'created_at' => now()->subHours($ageHours)])->save();

    return $row;
}

/**
 * The job only calls Telegram in production. Set after staffUser(): the seeder refuses to run there.
 */
function tdelProduction(): void
{
    app()->detectEnvironment(fn () => 'production');
}

function tdelTelegramOk(): void
{
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
}

/**
 * @return list<Request>
 */
function tdelDeleteCalls(): array
{
    return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/deleteMessage'))->map(fn (array $pair) => $pair[0])->all();
}

/**
 * @return Closure(): list<array{message: string, context: array<string, mixed>}>
 */
function tdelCaptureLogs(): Closure
{
    $logged = [];

    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = ['message' => $event->message, 'context' => $event->context];
    });

    return function () use (&$logged): array {
        return $logged;
    };
}

function tdelInboxAudits(): int
{
    return SettingsAudit::query()->where('area', SettingsArea::TelegramInbox->value)->count();
}

/**
 * @param  list<array{0: int, 1: int}>  $pairs
 */
function tdelRunCopies(array $pairs): void
{
    tdelProduction();
    (new DeleteForwardedCopies($pairs))->handle(app(TelegramClient::class));
}

/**
 * The snapshot of the Show component in a rendered page.
 */
function tdelSnapshot(string $html): string
{
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

    $snapshot = collect($matches[1])
        ->map(fn (string $raw) => html_entity_decode($raw, ENT_QUOTES))
        ->first(fn (string $candidate) => array_key_exists('conversationId', json_decode($candidate, true)['data'] ?? []));
    expect($snapshot)->not->toBeNull();

    return $snapshot;
}

// 1

it('lets a second Admin tab delete after the first: no error, one audit row, one job', function () {
    Bus::fake([DeleteForwardedCopies::class]);
    $admin = staffUser(Role::Admin);
    $chat = tdelThread();
    tdelCopyRow($chat, TDEL_CHAT, 901);
    confirmAdminPassword();

    $first = Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat]);
    $second = Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat]);

    $first->call('deleteConversation')->assertRedirect(route('admin.telegram.chats'));
    $second->call('deleteConversation')->assertRedirect(route('admin.telegram.chats'));

    expect(tdelInboxAudits())->toBe(1)
        ->and(SettingsAudit::query()->sole()->action)->toBe(AuditAction::Deleted);
    Bus::assertDispatchedTimes(DeleteForwardedCopies::class, 1);
});

// 2

it('refuses a tampered conversationId on deleteConversation and deletes nothing', function () {
    $admin = staffUser(Role::Admin);
    $mine = tdelThread();
    $other = tdelThread();
    confirmAdminPassword();
    $component = Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $mine]);

    expect(fn () => $component->set('conversationId', $other->id))->toThrow(CannotUpdateLockedPropertyException::class);
    try {
        $component->call('deleteConversation', $other->id);
    } catch (Throwable) {
        // A client argument is never a chat id.
    }

    expect(TelegramUser::query()->whereKey($other->id)->exists())->toBeTrue();
});

// 3

it('refuses the delete action to roles holding only one of inbox-all and bot, with no audit row', function (array $permissions) {
    SpatieRole::findOrCreate('half_admin', 'web')->syncPermissions($permissions);
    $user = staffUser(Role::ContentManager);
    $user->assignRole('half_admin');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user = $user->fresh();
    $chat = tdelThread();

    expect(fn () => app(DeleteConversation::class)->handle($chat, $user, null))->toThrow(AuthorizationException::class);
    expect(TelegramUser::query()->whereKey($chat->id)->exists())->toBeTrue()
        ->and(SettingsAudit::query()->count())->toBe(0);

    if (in_array('telegram.inbox', $permissions, true)) {
        confirmAdminPassword();
        Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat])->call('deleteConversation')->assertForbidden();
        expect(TelegramUser::query()->whereKey($chat->id)->exists())->toBeTrue();
    }
})->with([
    'inbox-all only' => [['telegram.inbox-all']],
    'bot only' => [['telegram.bot']],
    'inbox and inbox-all' => [['telegram.inbox', 'telegram.inbox-all']],
    'inbox and bot' => [['telegram.inbox', 'telegram.bot']],
]);

// 4

it('refuses the delete once the Admin role is removed after page load, even with a recent password', function () {
    $admin = staffUser(Role::Admin);
    $chat = tdelThread();
    confirmAdminPassword();
    $component = Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat])->assertOk();

    $admin->removeRole(Role::Admin);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $admin->unsetRelation('roles')->unsetRelation('permissions');

    $component->call('deleteConversation')->assertForbidden();

    expect(TelegramUser::query()->whereKey($chat->id)->exists())->toBeTrue()
        ->and(SettingsAudit::query()->count())->toBe(0);
});

// 5

it('queues only this thread\'s pairs, and a pair stored twice is called once', function () {
    $admin = staffUser(Role::Admin);
    $a = tdelThread();
    $b = tdelThread();
    tdelCopyRow($a, TDEL_CHAT, 901);
    tdelCopyRow($a, TDEL_CHAT, 901);
    tdelCopyRow($b, TDEL_CHAT, 777);
    $a->forceFill(['forward_header_chat_id' => TDEL_CHAT, 'forward_header_message_id' => 901, 'forward_header_at' => now()->subHour()])->save();

    Bus::fake([DeleteForwardedCopies::class]);
    app(DeleteConversation::class)->handle($a, $admin, null);

    Bus::assertDispatched(DeleteForwardedCopies::class, fn (DeleteForwardedCopies $job) => $job->pairs === [[TDEL_CHAT, 901]]);
    expect(TelegramUser::query()->whereKey($b->id)->exists())->toBeTrue();

    tdelTelegramOk();
    tdelRunCopies([[TDEL_CHAT, 901]]);
    expect(tdelDeleteCalls())->toHaveCount(1);
});

// 6

it('sends a supergroup id as an exact string', function () {
    tdelTelegramOk();

    tdelRunCopies([[-1001234567890, 5]]);

    $calls = tdelDeleteCalls();
    expect($calls)->toHaveCount(1)
        ->and($calls[0]['chat_id'])->toBe('-1001234567890')
        ->and($calls[0]['message_id'])->toBe(5)
        ->and($calls[0]->body())->toContain('"chat_id":"-1001234567890"');
});

// 7

it('keeps the token out of logs and reports on a connection failure or timeout, and counts correctly', function (string $detail) {
    Exceptions::fake();
    $logs = tdelCaptureLogs();
    $calls = 0;
    Http::fake(['api.telegram.org/*' => function (Request $request) use (&$calls, $detail) {
        if (++$calls === 1) {
            throw new ConnectionException($detail.' for '.$request->url());
        }

        return Http::response(['ok' => true, 'result' => true]);
    }]);

    tdelRunCopies([[TDEL_CHAT, 1], [TDEL_CHAT, 2], [TDEL_CHAT, 3]]);

    expect($calls)->toBe(3)
        ->and(json_encode($logs()))->not->toContain(TG_BOT_TOKEN)->not->toContain('AAFakeToken');
    $summary = collect($logs())->firstWhere('message', 'Telegram forwarded copies deleted');
    expect($summary['context'])->toBe(['deleted' => 2, 'failed' => 1]);
    Exceptions::assertNothingReported();
})->with([
    'connection refused' => ['cURL error 7: Failed to connect'],
    'timeout' => ['cURL error 28: Operation timed out after 5001 milliseconds'],
]);

it('ends the run on a 401 or 404', function (int $status) {
    $logs = tdelCaptureLogs();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => $status], $status)]);

    tdelRunCopies([[TDEL_CHAT, 1], [TDEL_CHAT, 2], [TDEL_CHAT, 3]]);

    expect(tdelDeleteCalls())->toHaveCount(1);
    $summary = collect($logs())->firstWhere('message', 'Telegram forwarded copies deleted');
    expect($summary['context'])->toBe(['deleted' => 0, 'failed' => 3])
        ->and(json_encode($logs()))->not->toContain(TG_BOT_TOKEN);
})->with([401, 404]);

// 8

it('keeps the audit row, reports once and still shows the success toast when the queue push fails', function () {
    Exceptions::fake();
    tdelTelegramOk();
    $admin = staffUser(Role::Admin);
    $chat = tdelThread();
    tdelCopyRow($chat, TDEL_CHAT, 901234);
    confirmAdminPassword();

    Queue::extend('tdel-push-fails', fn () => new class implements ConnectorInterface
    {
        public function connect(array $config): SyncQueue
        {
            return new class extends SyncQueue
            {
                public function push($job, $data = '', $queue = null)
                {
                    throw new RuntimeException('queue down');
                }
            };
        }
    });
    config(['queue.connections.tdel-push-fails' => ['driver' => 'tdel-push-fails'], 'queue.default' => 'tdel-push-fails']);

    Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat])->call('deleteConversation')->assertRedirect(route('admin.telegram.chats'));

    $toast = session('toast');
    expect(TelegramUser::query()->whereKey($chat->id)->exists())->toBeFalse()
        ->and(tdelInboxAudits())->toBe(1)
        ->and($toast['type'])->toBe('success')
        ->and(json_encode($toast))->not->toContain('901234')->not->toContain((string) abs(TDEL_CHAT));
    Exceptions::assertReportedCount(1);
});

// 9

it('survives a delete between the header and the copy of a forward: no exception, no failed job, no lost_target', function () {
    $chat = tdelThread(['telegram_id' => 5570002]);
    $row = TelegramMessage::factory()->for($chat)->media(TelegramMessageKind::Photo)->create();

    Http::fake(['api.telegram.org/*' => function (Request $request) use ($chat) {
        $method = basename((string) parse_url($request->url(), PHP_URL_PATH));

        if ($method === 'sendMessage') {
            TelegramUser::query()->whereKey($chat->id)->delete();
        }

        return Http::response(['ok' => true, 'result' => match ($method) {
            'getChat' => ['id' => (int) ($request['chat_id'] ?? 0), 'type' => 'supergroup', 'title' => 'Team'],
            'sendMessage' => ['message_id' => 900],
            default => ['message_id' => 901],
        }]);
    }]);

    (new ForwardTelegramMedia($row->id, $chat->id))->handle(app(TelegramClient::class));

    expect(TelegramUser::query()->count())->toBe(0)
        ->and(TelegramMessage::query()->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(Cache::has('telegram-forward-alert:lost_target'))->toBeFalse()
        ->and(Cache::has('telegram-forward-alert:refused_target'))->toBeFalse();
});

// 10

it('sends nothing and creates no row from queued work after the delete', function () {
    $chat = tdelThread(['telegram_id' => 5570003]);
    $inbound = TelegramMessage::factory()->for($chat)->media(TelegramMessageKind::Photo)->create(['text' => 'excerpt-after-delete']);
    $outbound = TelegramMessage::factory()->for($chat)->outbound()->create(['status' => TelegramMessageStatus::Pending, 'text' => 'reply-after-delete']);
    $chatId = $chat->id;
    $inboundId = $inbound->id;
    $outboundId = $outbound->id;
    TelegramUser::query()->whereKey($chatId)->delete();
    Http::fake();

    $forward = new ForwardTelegramMedia($inboundId, $chatId);
    $forward->handle(app(TelegramClient::class));
    $forward->handle(app(TelegramClient::class));

    (new DeliverBotMessage($outboundId))->handle(app(TelegramClient::class));

    $event = new TelegramInboxMessage($inboundId);
    expect($event->telegramMessage('uz'))->toBeNull();
    Bus::fake([SendTelegramMessage::class]);
    app(SendTelegramInboxAlert::class)->handle($event);
    Bus::assertNotDispatched(SendTelegramMessage::class);

    (new SendTelegramMessage('-1002222222222', '<b>excerpt-after-delete</b>', [], 'TelegramInboxMessage', inbox: true, telegramMessageId: $inboundId))->handle(app(TelegramClient::class));

    Http::assertNothingSent();
    expect(TelegramUser::query()->count())->toBe(0)->and(TelegramMessage::query()->count())->toBe(0);
});

// 11

it('creates no row and dispatches nothing for sendReply or resend from a stale tab, and returns no customer data', function () {
    config(['app.debug' => false]);
    Bus::fake([DeliverBotMessage::class]);
    $admin = staffUser(Role::Admin);
    $customer = Customer::factory()->create(['name' => 'Staletab Secret Co']);
    $chat = tdelThread(['name' => 'Staletab Person', 'customer_id' => $customer->id]);
    $html = $this->actingAs($admin)->get(route('admin.telegram.chats.show', $chat))->assertOk()->getContent();
    $snapshot = tdelSnapshot($html);

    TelegramUser::query()->whereKey($chat->id)->delete();

    $response = $this->withHeaders(['X-Livewire' => '1'])->postJson(app('livewire')->getUpdateUri(), [
        'components' => [['snapshot' => $snapshot, 'calls' => [['method' => 'sendReply', 'params' => [], 'metadata' => []]], 'updates' => ['reply' => 'stale reply text']]],
    ]);

    expect($response->getContent())->not->toContain('Staletab')->not->toContain('TypeError')->not->toContain(base_path())
        ->and(TelegramMessage::query()->count())->toBe(0);
    Bus::assertNotDispatched(DeliverBotMessage::class);
});

it('creates no row for a stale resend on a deleted chat', function () {
    Bus::fake([DeliverBotMessage::class]);
    $admin = staffUser(Role::Admin);
    $chat = tdelThread();
    $row = TelegramMessage::factory()->for($chat)->outbound($admin)->failed(500)->create(['sent_at' => null]);
    $component = Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat]);

    TelegramUser::query()->whereKey($chat->id)->delete();

    $component->call('resend', $row->id)->assertRedirect(route('admin.telegram.chats'));

    expect(TelegramMessage::query()->count())->toBe(0);
    Bus::assertNotDispatched(DeliverBotMessage::class);
});

// 12

it('creates a clean new row for an inbound update after the delete', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    $admin = staffUser(Role::Admin);
    $manager = staffUser(Role::SalesManager);
    $customer = Customer::factory()->create();
    $chat = tdelThread(['telegram_id' => 5570004, 'customer_id' => $customer->id, 'link_verified_at' => now(), 'link_verified_by_id' => $admin->id, 'manager_id' => $manager->id]);
    app(DeleteConversation::class)->handle($chat, $admin, null);

    postTelegramUpdate(telegramPrivateUpdate(9301, ['text' => 'hello again'], 5570004))->assertOk();

    $fresh = TelegramUser::query()->where('telegram_id', 5570004)->sole();
    expect($fresh->id)->not->toBe($chat->id)
        ->and($fresh->customer_id)->toBeNull()
        ->and($fresh->contact_id)->toBeNull()
        ->and($fresh->link_verified_at)->toBeNull()
        ->and($fresh->manager_id)->toBeNull()
        ->and($fresh->registered_at)->toBeNull();
});

// 13

it('survives a tampered auditPage and holds no IP on the status page', function (string $query) {
    config(['app.debug' => false]);
    $admin = staffUser(Role::Admin);
    SettingsAudit::record(SettingsArea::TelegramInbox, 'telegram.conversation.1', AuditAction::Banned, $admin, '203.0.113.77');

    $response = $this->actingAs($admin)->get(route('admin.telegram.status').'?'.$query);

    expect($response->status())->toBeLessThan(500);
    expect($response->getContent())->not->toContain('203.0.113.77')->not->toContain('Stack trace')->not->toContain('TypeError');
})->with([
    'negative' => ['auditPage=-1'],
    'string' => ['auditPage=abc'],
    'huge' => ['auditPage=99999999999999999999'],
    'array' => ['auditPage[]=1'],
]);

it('keeps the IP out of the status snapshot and shows a dash for a deleted actor', function () {
    $admin = staffUser(Role::Admin);
    $actor = staffUser(Role::SalesManager);
    SettingsAudit::record(SettingsArea::TelegramInbox, 'telegram.conversation.5', AuditAction::Banned, $actor, '203.0.113.77');
    DB::table('settings_audits')->update(['user_id' => null]);

    $page = Livewire::actingAs($admin)->test(Status::class);
    $html = $page->html();
    $start = (int) strpos($html, 'data-inbox-audit-row');
    $row = substr($html, $start, (int) strpos($html, '</tr>', $start) - $start);

    expect($row)->toContain(__('admin.common.empty_value'))->not->toContain($actor->name)
        ->and(json_encode($page->snapshot))->not->toContain('203.0.113.77')
        ->and($html)->not->toContain('203.0.113.77');
});

// 14

it('holds no identity data of a hidden row in the Customer block HTML or snapshot', function () {
    $owner = staffUser(Role::SalesManager);
    $other = staffUser(Role::SalesManager);
    $customer = Customer::factory()->create();
    $chat = tdelThread([
        'name' => 'Hiddenrow Person', 'username' => 'hiddenrowuser', 'phone' => '+998905551234', 'declared_company' => 'Hiddenrow Co LLC',
        'declared_inn' => '305123456', 'telegram_id' => 7712345678, 'customer_id' => $customer->id, 'link_verified_at' => now(),
        'banned_at' => now(), 'manager_id' => $owner->id,
    ]);

    $page = Livewire::actingAs($other)->test(CustomerShow::class, ['customer' => $customer])->assertSeeHtml('data-telegram-hidden');
    $blob = $page->html().json_encode($page->snapshot);

    foreach (['Hiddenrow', 'hiddenrowuser', '5551234', Phone::display('+998905551234'), '305123456', '7712345678', 'telegram_id', 'telegram-account-'.$chat->id.'"', 'chats/'.$chat->id.'"'] as $secret) {
        expect($blob)->not->toContain($secret);
    }

    expect($blob)->not->toContain(__('telegram_inbox.crm.verified'))->not->toContain(__('telegram_inbox.badges.banned'));
});

// 15

it('shows an unassigned thread\'s name and link to any sales manager, and drops both after a reassignment', function () {
    $one = staffUser(Role::SalesManager);
    $two = staffUser(Role::SalesManager);
    $customer = Customer::factory()->create();
    $chat = tdelThread(['name' => 'Shared Person', 'customer_id' => $customer->id]);

    foreach ([$one, $two] as $manager) {
        Livewire::actingAs($manager)->test(CustomerShow::class, ['customer' => $customer])
            ->assertSee('Shared Person')->assertSeeHtml('data-telegram-thread-link');
    }

    $tab = Livewire::actingAs($one)->test(CustomerShow::class, ['customer' => $customer])->assertSee('Shared Person');
    TelegramUser::query()->whereKey($chat->id)->update(['manager_id' => $two->id]);

    $tab->call('$refresh')->assertDontSee('Shared Person')->assertDontSeeHtml('data-telegram-thread-link')
        ->assertDontSeeHtml(route('admin.telegram.chats.show', $chat))->assertSeeHtml('data-telegram-hidden');
});

// 16

it('escapes a hostile name and username on the Customer and Lead blocks', function () {
    $admin = staffUser(Role::Admin);
    $customer = Customer::factory()->create();
    tdelThread(['name' => '<script>alert(1)</script>', 'username' => '"><img src=x onerror=alert(2)>', 'customer_id' => $customer->id]);
    $lead = Lead::factory()->create();
    $leadChat = tdelThread(['name' => '<script>alert(3)</script>', 'username' => '"><svg onload=alert(4)>']);
    TelegramMessage::factory()->for($leadChat)->create()->forceFill(['lead_id' => $lead->id])->save();

    $customerHtml = Livewire::actingAs($admin)->test(CustomerShow::class, ['customer' => $customer])->assertSeeHtml('data-customer-telegram')->html();
    $leadHtml = Livewire::actingAs($admin)->test(LeadShow::class, ['lead' => $lead])->assertSeeHtml('data-lead-telegram')->html();

    expect($customerHtml)->not->toContain('<script>alert(1)')->not->toContain('<img src=x onerror')
        ->and($customerHtml)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')->toContain('&lt;img src=x onerror=alert(2)&gt;')
        ->and($leadHtml)->not->toContain('<script>alert(3)')->not->toContain('<svg onload')
        ->and($leadHtml)->toContain('&lt;script&gt;alert(3)&lt;/script&gt;');
});

// 17

it('never lists a scrubbed banned row or an unregistered thread, not even for an Admin', function () {
    $admin = staffUser(Role::Admin);
    $customer = Customer::factory()->create();
    $scrubbed = TelegramUser::factory()->unregistered()->banned($admin)->create(['state' => TelegramUserState::Language, 'name' => 'Scrubbedrow Person', 'customer_id' => $customer->id]);
    $lead = Lead::factory()->create();
    $unregistered = TelegramUser::factory()->unregistered()->create(['first_name' => 'Unregistered', 'last_name' => 'Leadthread']);
    TelegramMessage::factory()->for($unregistered)->create()->forceFill(['lead_id' => $lead->id])->save();

    Livewire::actingAs($admin)->test(CustomerShow::class, ['customer' => $customer])
        ->assertDontSeeHtml('data-customer-telegram')->assertDontSee('Scrubbedrow')->assertDontSeeHtml(route('admin.telegram.chats.show', $scrubbed));
    Livewire::actingAs($admin)->test(LeadShow::class, ['lead' => $lead])
        ->assertDontSeeHtml('data-lead-telegram')->assertDontSee('Leadthread')->assertDontSeeHtml(route('admin.telegram.chats.show', $unregistered));
});

// 18

it('caps the Customer block at 10 accounts, and the Lead block query reads no text column', function () {
    $admin = staffUser(Role::Admin);
    $customer = Customer::factory()->create();
    TelegramUser::factory()->registered()->count(11)->create(['customer_id' => $customer->id]);

    $page = Livewire::actingAs($admin)->test(CustomerShow::class, ['customer' => $customer]);
    expect(substr_count($page->html(), 'wire:key="telegram-account-'))->toBe(CustomerShow::TELEGRAM_SHOWN);

    $lead = Lead::factory()->create();
    $chat = tdelThread();
    TelegramMessage::factory()->for($chat)->create(['text' => 'lead-block-secret-text'])->forceFill(['lead_id' => $lead->id])->save();

    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::actingAs($admin)->test(LeadShow::class, ['lead' => $lead])->assertSeeHtml('data-lead-telegram');
    $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains($sql, 'telegram_messages'));
    DB::disableQueryLog();

    expect($queries)->not->toBeEmpty();
    foreach ($queries as $sql) {
        expect($sql)->not->toMatch('/"text"|`text`|\*/');
    }
});

// 19

it('gives 403 or 404 and no customer data for a thread URL the manager may not open', function (string $segment) {
    $manager = staffUser(Role::SalesManager);
    $customer = Customer::factory()->create(['name' => 'Mountprobe Secret Co']);
    $foreign = tdelThread(['customer_id' => $customer->id], staffUser(Role::SalesManager));
    $id = $segment === 'foreign' ? (string) $foreign->id : $segment;

    $response = $this->actingAs($manager)->get('/admin/telegram/chats/'.$id);

    expect($response->status())->toBeIn([403, 404])
        ->and($response->getContent())->not->toContain('Mountprobe');
})->with(['foreign', 'nonexistent numeric' => '999999999', 'text' => 'abc', 'negative' => '-1', 'decimal' => '1.5']);

it('does not reveal a deleted chat to a manager who never had access', function () {
    $admin = staffUser(Role::Admin);
    $manager = staffUser(Role::SalesManager);
    $chat = tdelThread(['name' => 'Neverseen Person'], staffUser(Role::SalesManager));
    $snapshot = tdelSnapshot($this->actingAs($admin)->get(route('admin.telegram.chats.show', $chat))->assertOk()->getContent());
    $url = route('admin.telegram.chats.show', $chat);

    TelegramUser::query()->whereKey($chat->id)->delete();
    $this->app['auth']->forgetGuards();

    $response = $this->actingAs($manager)->withHeaders(['X-Livewire' => '1'])->postJson(app('livewire')->getUpdateUri(), [
        'components' => [['snapshot' => $snapshot, 'calls' => [['method' => 'refreshThread', 'params' => [], 'metadata' => []]], 'updates' => (object) []]],
    ]);

    expect($response->getContent())->not->toContain('Neverseen')
        ->and($response->json('components.0.effects.redirect'))->toBeNull()
        ->and(session('toast'))->toBeNull()
        ->and($url)->toContain((string) $chat->id);
});
