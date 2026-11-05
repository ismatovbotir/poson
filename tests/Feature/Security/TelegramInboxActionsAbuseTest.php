<?php

use App\Actions\Telegram\InboxRefused;
use App\Actions\Telegram\LinkConversationToCustomer;
use App\Actions\Telegram\ResendTelegramMessage;
use App\Actions\Telegram\RetryForward;
use App\Actions\Telegram\SendTelegramReply;
use App\Actions\Telegram\SetConversationBan;
use App\Actions\Telegram\VerifyConversationLink;
use App\Enums\ActivityType;
use App\Enums\ConversationStatus;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Enums\TelegramForwardStatus;
use App\Enums\TelegramMessageKind;
use App\Enums\TelegramMessageStatus;
use App\Enums\TelegramUserState;
use App\Jobs\DeliverBotMessage;
use App\Jobs\ForwardTelegramMedia;
use App\Livewire\Admin\Telegram\Chats\Show;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Phone;
use App\Support\Telegram\Bot\BotSwitch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * Plan 21 A2 to A5: abuse cases for replies, assign, ban, link and retry on the
 * staff inbox. Happy paths live in tests/Feature/Admin/Telegram/Chats*Test.php.
 * Bot API faked per test, test DB only.
 *
 * Not repeated here, already covered:
 *  - verify keeps the flag and records it in the Activity meta:
 *    ChatsLinkTest "keeps account_changed on verify and records it in the Activity meta (T21-36)";
 *  - an Admin's reply leaves a thread unassigned, a staff reply reopens a closed thread:
 *    ChatsReplyTest "leaves an unassigned thread unassigned when an Admin replies" and
 *    "reopens a closed thread on a staff reply, with an Activity row";
 *  - ban toggles past the limit keep bot and secret audit rows visible:
 *    ChatsLinkTest "keeps enable and secret rows visible when a manager toggles a ban past the limit (T21-35)".
 */
const IACT_TELEGRAM_ID = 5560001;

beforeEach(function () {
    telegramBotConfigured();
    Setting::query()->updateOrCreate(['key' => BotSwitch::KEY], ['group' => SettingsArea::TelegramBot->group(), 'value' => true]);
    SymfonyRequest::setTrustedHosts([]);
});

afterEach(function () {
    SymfonyRequest::setTrustedHosts([]);
});

function iactManager(): User
{
    return staffUser(Role::SalesManager);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function iactThread(array $attributes = [], ?User $manager = null): TelegramUser
{
    $factory = TelegramUser::factory()->registered();

    return ($manager ? $factory->assigned($manager) : $factory)->create($attributes + [
        'username' => null,
        'first_name' => 'Fname',
        'last_name' => 'Lname',
        'phone' => '+998901234567',
    ]);
}

function iactApi(): void
{
    $next = 100;

    Http::fake(['api.telegram.org/*' => function () use (&$next) {
        return Http::response(['ok' => true, 'result' => ['message_id' => $next++]]);
    }]);
}

/**
 * @return list<Request>
 */
function iactSent(): array
{
    return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/sendMessage'))->map(fn (array $pair) => $pair[0])->all();
}

function iactRunJob(TelegramMessage $row): void
{
    (new DeliverBotMessage($row->id))->handle(app(TelegramClient::class));
}

function iactRefused(Closure $call): bool
{
    try {
        $call();
    } catch (Throwable) {
        return true;
    }

    return false;
}

/**
 * Real Livewire update request on Show (persistent middleware, real snapshot).
 *
 * @param  array<string, mixed>  $updates
 * @param  list<array{method: string, params: list<mixed>, metadata: array<string, mixed>}>  $calls
 */
function iactUpdateRequest(User $user, TelegramUser $chat, array $updates, array $calls = []): TestResponse
{
    $html = test()->actingAs($user)->get(route('admin.telegram.chats.show', $chat))->assertOk()->getContent();
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

    $snapshot = collect($matches[1])
        ->map(fn (string $raw) => html_entity_decode($raw, ENT_QUOTES))
        ->first(fn (string $candidate) => array_key_exists('conversationId', json_decode($candidate, true)['data'] ?? []));
    expect($snapshot)->not->toBeNull();

    return test()->withHeaders(['X-Livewire' => '1'])->postJson(app('livewire')->getUpdateUri(), [
        'components' => [['snapshot' => $snapshot, 'calls' => $calls, 'updates' => $updates]],
    ]);
}

// 1

it('keeps the flag on X and writes no Activity on X when L is relinked away', function () {
    $admin = staffUser(Role::Admin);
    $customer = Customer::factory()->create();
    $elsewhere = Customer::factory()->create();
    $contact = Contact::factory()->create(['customer_id' => $customer->id]);
    $x = iactThread(['customer_id' => $customer->id, 'contact_id' => $contact->id]);
    $l = iactThread(['customer_id' => $customer->id, 'contact_id' => $contact->id]);
    $x->forceFill(['account_changed' => true])->save();

    app(LinkConversationToCustomer::class)->handle($l, $admin, $elsewhere->id);

    expect($x->fresh()->account_changed)->toBeTrue()
        ->and($x->activities()->count())->toBe(0)
        ->and($l->activities()->count())->toBe(1);
});

// 3

it('shows and records the phone signal on link and verify without rendering the customer phone', function (string $threadPhone, string $customerPhone, string $contactPhone, string $signal) {
    $admin = staffUser(Role::Admin);
    $customer = Customer::factory()->create(['phone' => $customerPhone]);
    $contact = Contact::factory()->create(['customer_id' => $customer->id, 'phone' => $contactPhone]);

    $unlinked = iactThread(['phone' => $threadPhone]);
    $page = Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $unlinked])
        ->set('linkOpen', true)->call('chooseCustomer', $customer->id)->set('linkContactId', $contact->id);
    $page->assertSeeHtml('data-phone-signal="'.$signal.'"')->assertSee(__('telegram_inbox.crm.phone_signal.'.$signal));
    $page->call('link');
    expect($unlinked->activities()->sole()->meta)->toMatchArray(['action' => 'link', 'phone_signal' => $signal]);

    $linked = iactThread(['phone' => $threadPhone, 'customer_id' => $customer->id, 'contact_id' => $contact->id]);
    $verify = Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $linked]);
    $verify->assertSeeHtml('data-phone-signal="'.$signal.'"');
    $verify->call('verifyLink');
    expect($linked->activities()->sole()->meta)->toMatchArray(['action' => 'verify_link', 'phone_signal' => $signal]);

    if ($customerPhone !== $threadPhone) {
        foreach ([$page->html(), $verify->html()] as $html) {
            expect($html)->not->toContain($customerPhone)->not->toContain(Phone::display($customerPhone))->not->toContain('7776655');
        }
    }
})->with([
    'customer phone, a different contact chosen' => ['+998901234567', '+998901234567', '+998905550000', 'matches_other'],
    'contact phone matches' => ['+998901234567', '+998907776655', '+998901234567', 'matches'],
    'no match' => ['+998901234567', '+998907776655', '+998905550000', 'no_match'],
    'anonymous +888' => ['+88801234567', '+88801234567', '+88801234567', 'anonymous'],
]);

// 4

it('refuses RetryForward for a stale Failed model when the DB says Forwarded', function () {
    Bus::fake([ForwardTelegramMedia::class]);
    $admin = staffUser(Role::Admin);
    $chat = iactThread();
    $row = TelegramMessage::factory()->for($chat)->media(TelegramMessageKind::Document)->create(['forward_status' => TelegramForwardStatus::Failed]);
    $stale = TelegramMessage::query()->findOrFail($row->id);
    TelegramMessage::query()->whereKey($row->id)->update(['forward_status' => TelegramForwardStatus::Forwarded]);
    Cache::put(ForwardTelegramMedia::capDecisionKey($row->id), 'counted', 600);

    expect(fn () => app(RetryForward::class)->handle($admin, $stale))
        ->toThrow(fn (InboxRefused $refused) => expect($refused->reason)->toBe('forward_not_retryable'));

    Bus::assertNotDispatched(ForwardTelegramMedia::class);
    expect(SettingsAudit::query()->count())->toBe(0)
        ->and(Cache::get(ForwardTelegramMedia::capDecisionKey($row->id)))->toBe('counted')
        ->and(RateLimiter::attempts(RetryForward::PER_MINUTE_KEY.$row->id))->toBe(0)
        ->and(RateLimiter::attempts(RetryForward::PER_USER_KEY.$admin->id))->toBe(0)
        ->and($row->fresh()->forward_status)->toBe(TelegramForwardStatus::Forwarded);
});

// 5

it('gives 403 on link to another manager\'s customer and renders nothing of it when chosen', function () {
    $mine = iactManager();
    $other = iactManager();
    $foreign = Customer::factory()->managedBy($other)->create(['name' => 'Foreignco Secret', 'phone' => '+998907771122']);
    $chat = iactThread([], $mine);

    Livewire::actingAs($mine)->test(Show::class, ['telegramUser' => $chat])
        ->set('linkCustomerId', $foreign->id)->call('link')->assertForbidden();
    expect($chat->fresh()->customer_id)->toBeNull()
        ->and($chat->activities()->count())->toBe(0);

    $picker = Livewire::actingAs($mine)->test(Show::class, ['telegramUser' => $chat])
        ->set('linkOpen', true)->call('chooseCustomer', $foreign->id);
    $picker->assertDontSee('Foreignco Secret')->assertDontSeeHtml('data-chosen-customer');
    expect($picker->html())->not->toContain('7771122')->not->toContain(Phone::display('+998907771122'));
});

// 6

it('gives 403 when a manager changes only the contact of an already linked thread', function () {
    $manager = iactManager();
    $customer = Customer::factory()->managedBy($manager)->create();
    $first = Contact::factory()->create(['customer_id' => $customer->id]);
    $second = Contact::factory()->create(['customer_id' => $customer->id]);
    $chat = iactThread(['customer_id' => $customer->id, 'contact_id' => $first->id], $manager);

    Livewire::actingAs($manager)->test(Show::class, ['telegramUser' => $chat])
        ->set('linkCustomerId', $customer->id)->set('linkContactId', $second->id)->call('link')->assertForbidden();

    expect($chat->fresh()->contact_id)->toBe($first->id)
        ->and($chat->activities()->count())->toBe(0);
});

// 7

it('lets only the first of two stale link screens through, with one Activity row', function () {
    $one = iactManager();
    $two = iactManager();
    $customer = Customer::factory()->create();
    $chat = iactThread();

    $first = Livewire::actingAs($one)->test(Show::class, ['telegramUser' => $chat]);
    $second = Livewire::actingAs($two)->test(Show::class, ['telegramUser' => $chat]);

    // actingAs is global and Show binds its snapshot to the viewer (viewerId), so each screen acts as its own user.
    $this->actingAs($one);
    $first->set('linkCustomerId', $customer->id)->call('link')->assertOk();
    $this->actingAs($two);
    $second->set('linkCustomerId', $customer->id)->call('link')->assertForbidden();

    expect($chat->fresh()->customer_id)->toBe($customer->id)
        ->and($chat->activities()->count())->toBe(1);
});

it('refuses the second manager\'s stale link screen cleanly in a real update request', function () {
    config(['app.debug' => false]);
    $one = iactManager();
    $two = iactManager();
    $customer = Customer::factory()->create();
    $chat = iactThread();

    $html = $this->actingAs($two)->get(route('admin.telegram.chats.show', $chat))->assertOk()->getContent();
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);
    $snapshot = collect($matches[1])
        ->map(fn (string $raw) => html_entity_decode($raw, ENT_QUOTES))
        ->first(fn (string $candidate) => array_key_exists('conversationId', json_decode($candidate, true)['data'] ?? []));
    expect($snapshot)->not->toBeNull();

    app(LinkConversationToCustomer::class)->handle($chat, $one, $customer->id);

    $response = $this->withHeaders(['X-Livewire' => '1'])->postJson(app('livewire')->getUpdateUri(), [
        'components' => [['snapshot' => $snapshot, 'calls' => [['method' => 'link', 'params' => [], 'metadata' => []]], 'updates' => ['linkCustomerId' => $customer->id]]],
    ]);

    $response->assertForbidden();
    expect($response->getContent())->not->toContain('Stack trace')->not->toContain(base_path())
        ->and($chat->fresh()->customer_id)->toBe($customer->id)
        ->and($chat->activities()->count())->toBe(1);
});

// 8

it('changes nothing when assignTarget, linkContactId, linkCustomerId or reply are tampered', function (string $property, mixed $value, string $call) {
    Bus::fake([DeliverBotMessage::class]);
    $admin = staffUser(Role::Admin);
    $chat = iactThread();
    $component = Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat]);

    if (! iactRefused(fn () => $component->set($property, $value))) {
        iactRefused(fn () => $component->call($call));
    }

    expect($chat->fresh()->manager_id)->toBeNull()
        ->and($chat->fresh()->customer_id)->toBeNull()
        ->and($chat->activities()->count())->toBe(0)
        ->and(TelegramMessage::query()->count())->toBe(0);
    Bus::assertNotDispatched(DeliverBotMessage::class);
})->with([
    'assignTarget array' => ['assignTarget', [1], 'assign'],
    'assignTarget text' => ['assignTarget', 'abc', 'assign'],
    'linkCustomerId array' => ['linkCustomerId', [1], 'link'],
    'linkCustomerId text' => ['linkCustomerId', 'abc', 'link'],
    'linkContactId array' => ['linkContactId', [1], 'link'],
    'linkContactId text' => ['linkContactId', 'abc', 'link'],
    'reply array' => ['reply', ['x'], 'sendReply'],
]);

it('leaks no error detail on a tampered real update request', function (array $updates, string $call) {
    config(['app.debug' => false]);
    Bus::fake([DeliverBotMessage::class]);
    $admin = staffUser(Role::Admin);
    $chat = iactThread();

    $response = iactUpdateRequest($admin, $chat, $updates, [['method' => $call, 'params' => [], 'metadata' => []]]);

    expect($response->getContent())->not->toContain('TypeError')->not->toContain('Stack trace')->not->toContain(base_path())->not->toContain('vendor/')
        ->and($chat->fresh()->manager_id)->toBeNull()
        ->and($chat->fresh()->customer_id)->toBeNull()
        ->and(TelegramMessage::query()->count())->toBe(0);
})->with([
    'assignTarget array' => [['assignTarget' => ['x']], 'assign'],
    'linkCustomerId text' => [['linkCustomerId' => 'abc'], 'link'],
    'reply array' => [['reply' => ['x']], 'sendReply'],
]);

// 9

it('hides customer and contact names from a telegram.inbox user without customers.view', function () {
    SpatieRole::findOrCreate('inbox_viewer', 'web')->givePermissionTo('telegram.inbox');
    $user = staffUser(Role::ContentManager);
    $user->assignRole('inbox_viewer');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user = $user->fresh();
    expect($user->can('customers.view'))->toBeFalse()->and($user->can('telegram.inbox'))->toBeTrue();

    $customer = Customer::factory()->create(['name' => 'Hiddenco Name']);
    $contact = Contact::factory()->create(['customer_id' => $customer->id, 'name' => 'Hidden Person']);
    $chat = iactThread(['customer_id' => $customer->id, 'contact_id' => $contact->id]);

    $component = Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat])->assertOk();

    $component->assertDontSee('Hiddenco Name')->assertDontSee('Hidden Person')
        ->assertSeeHtml('data-customer-hidden')->assertSeeHtml('data-contact-hidden');
});

// 10

it('sends nothing when the customer is banned after the reply was queued, and an unban sends nothing by itself', function () {
    Bus::fake([DeliverBotMessage::class]);
    iactApi();
    $author = iactManager();
    $chat = iactThread(['telegram_id' => IACT_TELEGRAM_ID], $author);

    Livewire::actingAs($author)->test(Show::class, ['telegramUser' => $chat])->set('reply', 'queued text')->call('sendReply');
    $row = TelegramMessage::query()->sole();
    TelegramUser::query()->whereKey($chat->id)->update(['banned_at' => now()]);

    iactRunJob($row);
    expect(iactSent())->toBeEmpty()
        ->and($row->fresh()->status)->toBe(TelegramMessageStatus::Pending);

    app(SetConversationBan::class)->unban($chat, staffUser(Role::Admin), null);
    expect(iactSent())->toBeEmpty()
        ->and($row->fresh()->status)->toBe(TelegramMessageStatus::Pending);
    Bus::assertDispatchedTimes(DeliverBotMessage::class, 1);

    // Too fresh to resend: only the author's later explicit resend queues it again.
    expect(fn () => app(ResendTelegramMessage::class)->handle($row->fresh(), $author))
        ->toThrow(fn (InboxRefused $refused) => expect($refused->reason)->toBe('not_retryable'));

    $this->travel(TelegramMessage::STUCK_MINUTES + 1)->minutes();
    expect(app(ResendTelegramMessage::class)->handle($row->fresh(), $author))->toBeTrue();
    Bus::assertDispatchedTimes(DeliverBotMessage::class, 2);

    iactRunJob($row);
    expect(iactSent())->toHaveCount(1)->and($row->fresh()->status)->toBe(TelegramMessageStatus::Sent);
});

// 11

it('marks the customer blocked on a 403, refuses the next reply and sends the resend once after a member update', function () {
    Bus::fake([DeliverBotMessage::class]);
    Http::fake(['api.telegram.org/*' => Http::sequence()
        ->push(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 403)
        ->push(['ok' => true, 'result' => ['message_id' => 78]])]);
    $author = iactManager();
    $chat = iactThread(['telegram_id' => IACT_TELEGRAM_ID], $author);

    $component = Livewire::actingAs($author)->test(Show::class, ['telegramUser' => $chat])->set('reply', 'queued text')->call('sendReply');
    $row = TelegramMessage::query()->sole();

    iactRunJob($row);
    expect($chat->fresh()->blocked_at)->not->toBeNull()
        ->and($row->fresh()->status)->toBe(TelegramMessageStatus::Failed)
        ->and($row->fresh()->error_code)->toBe(403);

    $component->set('reply', 'second try')->call('sendReply')->assertDispatched('toast', type: 'error');
    expect(TelegramMessage::query()->count())->toBe(1);

    postTelegramUpdate(['update_id' => 9101, 'my_chat_member' => [
        'chat' => ['id' => IACT_TELEGRAM_ID, 'type' => 'private'], 'from' => ['id' => IACT_TELEGRAM_ID, 'is_bot' => false],
        'old_chat_member' => ['status' => 'kicked'], 'new_chat_member' => ['status' => 'member'],
    ]])->assertOk();
    expect($chat->fresh()->blocked_at)->toBeNull();

    Livewire::actingAs($author)->test(Show::class, ['telegramUser' => $chat])->call('resend', $row->id);
    Bus::assertDispatchedTimes(DeliverBotMessage::class, 2);

    iactRunJob($row);
    expect($row->fresh()->status)->toBe(TelegramMessageStatus::Sent)
        ->and(iactSent())->toHaveCount(2);
});

// 12

it('ends a resend during 5xx backoff with two jobs and one delivery', function () {
    Bus::fake([DeliverBotMessage::class]);
    Http::fake(['api.telegram.org/*' => Http::sequence()
        ->push(['ok' => false, 'error_code' => 502], 502)
        ->push(['ok' => true, 'result' => ['message_id' => 79]])]);
    $author = iactManager();
    $chat = iactThread([], $author);

    Livewire::actingAs($author)->test(Show::class, ['telegramUser' => $chat])->set('reply', 'backoff text')->call('sendReply');
    $row = TelegramMessage::query()->sole();

    expect(fn () => iactRunJob($row))->toThrow(TelegramException::class);
    expect($row->fresh()->status)->toBe(TelegramMessageStatus::Pending);

    $this->travel(TelegramMessage::STUCK_MINUTES + 1)->minutes();
    Livewire::actingAs($author)->test(Show::class, ['telegramUser' => $chat])->call('resend', $row->id);
    Bus::assertDispatchedTimes(DeliverBotMessage::class, 2);

    iactRunJob($row);
    iactRunJob($row);

    expect($row->fresh()->status)->toBe(TelegramMessageStatus::Sent)
        ->and(iactSent())->toHaveCount(2)
        ->and(collect(iactSent())->filter(fn (Request $request) => $request['text'] === 'backoff text')->count())->toBe(2)
        ->and(TelegramMessage::query()->whereNotNull('telegram_message_id')->count())->toBe(1);
});

// 13

it('keeps one person\'s reply limit and double-submit keys from blocking another', function () {
    iactApi();
    $a = iactManager();
    $b = iactManager();
    $admin = staffUser(Role::Admin);
    $aThread = iactThread([], $a);
    $bThread = iactThread([], $b);
    $aOther = iactThread([], $a);

    foreach (range(1, SendTelegramReply::PER_MINUTE) as $_) {
        RateLimiter::hit(SendTelegramReply::LIMIT_KEY.$a->id, 60);
    }

    Livewire::actingAs($a)->test(Show::class, ['telegramUser' => $aThread])->set('reply', 'same')->call('sendReply', 'token-x')->assertDispatched('toast', type: 'error');
    expect(TelegramMessage::query()->count())->toBe(0);

    Livewire::actingAs($b)->test(Show::class, ['telegramUser' => $bThread])->set('reply', 'same')->call('sendReply', 'token-x')->assertDispatched('toast', type: 'success');
    expect(TelegramMessage::query()->count())->toBe(1);

    RateLimiter::clear(SendTelegramReply::LIMIT_KEY.$a->id);
    Livewire::actingAs($a)->test(Show::class, ['telegramUser' => $aThread])->set('reply', 'same')->call('sendReply', 'token-x');
    Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $aThread])->set('reply', 'same')->call('sendReply', 'token-x');
    Livewire::actingAs($a)->test(Show::class, ['telegramUser' => $aOther])->set('reply', 'same')->call('sendReply', 'token-x');

    expect(TelegramMessage::query()->count())->toBe(4)
        ->and(TelegramMessage::query()->where('telegram_user_id', $aThread->id)->count())->toBe(2)
        ->and(TelegramMessage::query()->where('telegram_user_id', $aOther->id)->count())->toBe(1);
});

// 14

it('escapes a hostile file_name and mime_type on the thread', function () {
    $chat = iactThread();
    TelegramMessage::factory()->for($chat)->media(TelegramMessageKind::Document)->create([
        'media' => ['file_name' => '<script>alert(1)</script>.pdf', 'mime_type' => '"><script>alert(2)</script>', 'file_size' => 10],
    ]);

    $html = Livewire::actingAs(staffUser(Role::Admin))->test(Show::class, ['telegramUser' => $chat])->html();

    expect($html)->not->toContain('<script>alert(1)')->not->toContain('<script>alert(2)')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;.pdf')
        ->toContain('&quot;&gt;&lt;script&gt;alert(2)&lt;/script&gt;');
});

// 15

it('shows no success toast when an action changed nothing', function () {
    $admin = staffUser(Role::Admin);
    $customer = Customer::factory()->create();
    $contact = Contact::factory()->create(['customer_id' => $customer->id]);

    $cases = [
        'unban' => fn () => [iactThread(), 'unban', []],
        'ban again' => fn () => [iactThread(['banned_at' => now()]), 'ban', []],
        'take own' => fn () => [iactThread([], $admin), 'take', []],
        'close closed' => fn () => [iactThread(['conversation_status' => ConversationStatus::Closed]), 'close', []],
        'reopen open' => fn () => [iactThread(), 'reopen', []],
        'verify verified' => fn () => [iactThread(['customer_id' => $customer->id, 'link_verified_at' => now(), 'link_verified_by_id' => $admin->id]), 'verifyLink', []],
    ];

    foreach ($cases as $label => $make) {
        [$chat, $method, $params] = $make();
        $component = Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat])->call($method, ...$params);

        $component->assertDispatched('toast', type: 'info')->assertNotDispatched('toast', type: 'success');
    }

    $same = iactThread(['customer_id' => $customer->id, 'contact_id' => $contact->id]);
    Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $same])
        ->set('linkCustomerId', $customer->id)->set('linkContactId', $contact->id)->call('link')
        ->assertDispatched('toast', type: 'info')->assertNotDispatched('toast', type: 'success');

    $assigned = iactThread([], $admin);
    Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $assigned])
        ->set('assignTarget', $admin->id)->call('assign')
        ->assertDispatched('toast', type: 'info')->assertNotDispatched('toast', type: 'success');
});

// 16

it('deletes a scrubbed banned row with its activities on unban and lets the user start again as new', function () {
    iactApi();
    $admin = staffUser(Role::Admin);
    $scrubbed = TelegramUser::factory()->unregistered()->banned($admin)->create(['telegram_id' => IACT_TELEGRAM_ID, 'state' => TelegramUserState::Language]);
    $scrubbed->activities()->create(['user_id' => $admin->id, 'type' => ActivityType::StatusChange, 'meta' => ['action' => 'ban'], 'created_at' => now()]);

    Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $scrubbed])->call('unban');

    expect(TelegramUser::query()->whereKey($scrubbed->id)->exists())->toBeFalse()
        ->and(Activity::query()->where('subject_id', $scrubbed->id)->where('subject_type', $scrubbed->getMorphClass())->count())->toBe(0);

    postTelegramUpdate(telegramPrivateUpdate(9201, ['text' => '/start'], IACT_TELEGRAM_ID))->assertOk();

    $fresh = TelegramUser::query()->where('telegram_id', IACT_TELEGRAM_ID)->sole();
    expect($fresh->id)->not->toBe($scrubbed->id)
        ->and($fresh->banned_at)->toBeNull()
        ->and($fresh->registered_at)->toBeNull();
});

it('keeps registration, link and verification when a registered row is unbanned', function () {
    $admin = staffUser(Role::Admin);
    $customer = Customer::factory()->create();
    $chat = iactThread(['customer_id' => $customer->id, 'link_verified_at' => now()->subDay(), 'link_verified_by_id' => $admin->id, 'banned_at' => now(), 'banned_by_id' => $admin->id]);
    $registeredAt = $chat->registered_at;

    Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat])->call('unban');

    $row = $chat->fresh();
    expect($row)->not->toBeNull()
        ->and($row->banned_at)->toBeNull()
        ->and($row->registered_at->equalTo($registeredAt))->toBeTrue()
        ->and($row->customer_id)->toBe($customer->id)
        ->and($row->link_verified_at)->not->toBeNull()
        ->and($row->link_verified_by_id)->toBe($admin->id)
        ->and($row->activities()->sole()->meta)->toMatchArray(['action' => 'unban']);
});

// 17

it('lists only this thread\'s latest 10 staff actions in the history, with no message text', function () {
    $admin = staffUser(Role::Admin);
    $chat = iactThread(['name' => 'History Customer']);
    $other = iactThread();
    TelegramMessage::factory()->for($chat)->create(['text' => 'secret-history-message-body']);
    $ids = [];

    foreach (range(1, 12) as $i) {
        $ids[$i] = $chat->activities()->create(['user_id' => $admin->id, 'type' => ActivityType::Assignment, 'meta' => ['action' => 'assign', 'from_id' => null, 'to_id' => $admin->id], 'created_at' => now()->subMinutes(100 - $i)])->id;
    }

    $note = $chat->activities()->create(['user_id' => $admin->id, 'type' => ActivityType::Note, 'body' => 'internal note body', 'created_at' => now()]);
    $foreign = $other->activities()->create(['user_id' => $admin->id, 'type' => ActivityType::Assignment, 'meta' => ['action' => 'assign', 'to_id' => $admin->id], 'created_at' => now()]);

    $html = Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat])->set('historyOpen', true)->html();
    $start = (int) strpos($html, 'data-history>');
    $list = substr($html, $start, (int) strpos($html, '</ul>', $start) - $start);

    expect(substr_count($list, 'wire:key="history-'))->toBe(10)
        ->and($list)->toContain('history-'.$ids[12].'"')->toContain('history-'.$ids[3].'"')
        ->not->toContain('history-'.$ids[2].'"')->not->toContain('history-'.$ids[1].'"')
        ->not->toContain('history-'.$note->id.'"')->not->toContain('history-'.$foreign->id.'"')
        ->not->toContain('secret-history-message-body')->not->toContain('internal note body');
});

it('refuses the history to a user without view of the thread', function () {
    $other = iactManager();
    $chat = iactThread([], iactManager());

    Livewire::actingAs($other)->test(Show::class, ['telegramUser' => $chat])->assertForbidden();

    $own = iactThread([], $other);
    $component = Livewire::actingAs($other)->test(Show::class, ['telegramUser' => $own])->set('historyOpen', true)->assertOk();
    $own->forceFill(['manager_id' => iactManager()->id])->save();

    $component->call('$refresh')->assertForbidden();
});

// 18

it('shows the failed-reply hint to the new owner after reassignment, with no resend button and a refused resend', function () {
    Bus::fake([DeliverBotMessage::class]);
    $author = iactManager();
    $newOwner = iactManager();
    $chat = iactThread([], $author);
    $row = TelegramMessage::factory()->for($chat)->outbound($author)->failed(500)->create(['text' => 'failed out', 'sent_at' => null]);
    TelegramUser::query()->whereKey($chat->id)->update(['manager_id' => $newOwner->id]);

    $component = Livewire::actingAs($newOwner)->test(Show::class, ['telegramUser' => $chat->fresh()])->assertOk();

    $component->assertSeeHtml('data-resend-hint="'.$row->id.'"')->assertDontSeeHtml('data-resend="'.$row->id.'"');
    $component->call('resend', $row->id)->assertForbidden();

    Bus::assertNotDispatched(DeliverBotMessage::class);
    expect($row->fresh()->status)->toBe(TelegramMessageStatus::Failed);
});

it('keeps the stale-model check on LinkConversationToCustomer and VerifyConversationLink', function () {
    $manager = iactManager();
    $customer = Customer::factory()->create();
    $chat = iactThread();
    $stale = TelegramUser::query()->findOrFail($chat->id);
    TelegramUser::query()->whereKey($chat->id)->update(['manager_id' => iactManager()->id]);

    expect(fn () => app(LinkConversationToCustomer::class)->handle($stale, $manager, $customer->id))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(VerifyConversationLink::class)->handle($stale, $manager, null))
        ->toThrow(AuthorizationException::class);
    expect($chat->fresh()->customer_id)->toBeNull();
});
