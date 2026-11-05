<?php

use App\Actions\Telegram\ConfigureTelegramWebhook;
use App\Actions\Telegram\ConvertTelegramToLead;
use App\Actions\Telegram\DeleteConversation;
use App\Actions\Telegram\InboxRefused;
use App\Actions\Telegram\ScrubBannedTelegramUsers;
use App\Actions\Telegram\SetBotEnabled;
use App\Actions\Telegram\SetConversationBan;
use App\Actions\Telegram\TelegramBotException;
use App\Enums\LeadType;
use App\Enums\Role;
use App\Livewire\Admin\Leads\Show as LeadShow;
use App\Livewire\Admin\Telegram\Chats\Show;
use App\Livewire\Site\LeadForm;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Page;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Models\User;
use App\Support\Telegram\Bot\BotSwitch;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * Plan 21 B2: abuse cases for converting chat messages to a lead. Happy paths and
 * the first line of defence live in tests/Feature/Admin/Telegram/ChatsConvertTest.php.
 * Bot API faked, test DB only.
 *
 * Not repeated, already covered there:
 *  - case 1 (no_match lead: no customer line, no quote path, no Dealer level):
 *    "does not put an unverified no_match link on the lead (T21-39)" and
 *    "gives a no_match lead no quote path, customer or Dealer price level (T21-39)";
 *  - case 2 (banned after convert stays, banned without lead is scrubbed):
 *    "keeps a lead-linked thread through prune and scrub, also when banned after the convert" and
 *    "refuses a banned thread as Admin with no lead, and that row is still scrubbed (T21-40)";
 *  - case 3 except the missing alert: "limits a manager to 30 converts an hour…", "does not limit an Admin",
 *    "uses nothing of the limit on a refused or repeated call", "refuses a selection longer than the lead keeps…";
 *  - case 7: "refuses a selection longer than the lead keeps, linking nothing (T21-40)".
 *
 * Helper prefix tcab: tconv is already taken by ChatsConvertTest.
 */
beforeEach(function () {
    telegramBotConfigured();
    SymfonyRequest::setTrustedHosts([]);
});

afterEach(function () {
    SymfonyRequest::setTrustedHosts([]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function tcabThread(array $attributes = [], ?User $manager = null): TelegramUser
{
    $factory = TelegramUser::factory()->registered();

    return ($manager ? $factory->assigned($manager) : $factory)->create(['name' => 'Bot Person', 'username' => null, ...$attributes]);
}

function tcabInbound(TelegramUser $chat, ?string $text = 'Need a scanner'): TelegramMessage
{
    return TelegramMessage::factory()->for($chat)->inbound()->create(['text' => $text]);
}

/**
 * @param  array<array-key, mixed>|null  $ids
 */
function tcabRun(TelegramUser $chat, User $actor, ?array $ids = null, LeadType $type = LeadType::Quote): ?Lead
{
    return app(ConvertTelegramToLead::class)->handle($chat, $actor, $ids ?? [tcabInbound($chat)->id], $type);
}

function tcabTelegramOk(): void
{
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]])]);
}

/**
 * A user with the base role admitted by the admin middleware plus a custom role holding exactly these permissions.
 *
 * @param  list<string>  $permissions
 */
function tcabActor(array $permissions, string $roleName): User
{
    $user = staffUser(Role::ContentManager);
    SpatieRole::findOrCreate($roleName, 'web')->syncPermissions($permissions);
    $user->assignRole($roleName);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

// 3

it('makes no lead and no alert on the over-limit call', function () {
    tcabTelegramOk();
    $manager = staffUser(Role::SalesManager);
    $chat = tcabThread([], $manager);
    RateLimiter::increment(ConvertTelegramToLead::LIMIT_KEY.$manager->id, 3600, ConvertTelegramToLead::PER_HOUR);
    $message = tcabInbound($chat);

    expect(fn () => tcabRun($chat, $manager, [$message->id]))
        ->toThrow(fn (InboxRefused $refused) => expect($refused->reason)->toBe('too_many_actions'));

    expect(Lead::query()->count())->toBe(0)
        ->and(collect(telegramApiCalls())->where('method', 'sendMessage'))->toBeEmpty()
        ->and($message->fresh()->lead_id)->toBeNull()
        ->and($chat->activities()->count())->toBe(0);
});

// 4

it('falls back to the actor when the thread manager is inactive', function () {
    $inactive = User::factory()->withRole(Role::SalesManager)->inactive()->create();
    $chat = tcabThread([], $inactive);
    // A manager sees only their own or unassigned threads, so an inbox-all actor opens the inactive owner's thread.
    $actor = tcabActor(['telegram.inbox', 'telegram.inbox-all', 'leads.create', 'leads.sales'], 'convert_viewer');

    expect(tcabRun($chat, $actor)->manager_id)->toBe($actor->id);
});

it('falls back to the actor when the thread manager lacks the type permission, and uses a real non-Admin owner for service', function () {
    $salesOwner = staffUser(Role::SalesManager);
    $engineer = staffUser(Role::ServiceEngineer);
    $actor = tcabActor(['telegram.inbox', 'telegram.inbox-all', 'leads.create', 'leads.service'], 'convert_service');

    $ownedBySales = tcabThread([], $salesOwner);
    expect(tcabRun($ownedBySales, $actor, null, LeadType::ServiceRequest)->manager_id)->toBe($actor->id);

    $ownedByEngineer = tcabThread([], $engineer);
    expect(tcabRun($ownedByEngineer, $actor, null, LeadType::ServiceRequest)->manager_id)->toBe($engineer->id);
});

it('leaves the lead unassigned for a non-Admin actor with leads.view-all', function () {
    $supervisor = tcabActor(['telegram.inbox', 'leads.create', 'leads.sales', 'leads.view-all'], 'convert_supervisor');
    $chat = tcabThread();

    expect(tcabRun($chat, $supervisor)->manager_id)->toBeNull();
});

// 5

it('escapes name, company and message in the alert body and keeps it one safe flat line', function () {
    tcabTelegramOk();
    $admin = staffUser(Role::Admin);
    $hostile = '<b>Bold</b> & <a href="https://evil.example/x">click</a>';
    $chat = tcabThread([
        'name' => $hostile, 'declared_company' => $hostile, 'declared_inn' => '305123456', 'telegram_id' => 7712345679,
    ]);
    $tail = str_repeat('a', 290).' &amp; <b>x</b> tail';
    $forged = tcabInbound($chat, "Forged first\nCustomer: Evil LLC\nContact: +998000000000 ".$hostile);
    $long = tcabInbound($chat, $tail);

    tcabRun($chat, $admin, [$forged->id, $long->id]);

    $body = collect(telegramApiCalls())->firstWhere('method', 'sendMessage')['data']['text'];

    expect($body)->not->toContain('<a href')->not->toContain('<b>Bold</b>')->not->toContain('305123456')->not->toContain('7712345679')
        ->and($body)->toContain('&lt;b&gt;Bold&lt;/b&gt;')->toContain('&lt;a href=&quot;https://evil.example/x&quot;&gt;');

    foreach (explode("\n", $body) as $line) {
        expect($line)->not->toStartWith('Customer:')->not->toStartWith('Contact:')->not->toStartWith('Evil LLC');
    }

    expect(substr_count($body, "\n"))->toBeLessThan(8);
    // Every ampersand starts a complete entity: the excerpt is cut before it is escaped.
    expect(preg_match_all('/&(?!(amp|lt|gt|quot|#039);)/', $body))->toBe(0);
});

// 6

it('still refuses to enable the bot with an active reviewed offer page and no usable privacy page', function () {
    Http::fake(['api.telegram.org/*' => function ($request) {
        return match (basename(parse_url($request->url(), PHP_URL_PATH))) {
            'getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => ConfigureTelegramWebhook::expectedUrl(), 'pending_update_count' => 0]]),
            'getChat' => Http::response(['ok' => true, 'result' => ['id' => -1001111111111, 'type' => 'supergroup']]),
            'getMe' => Http::response(['ok' => true, 'result' => ['id' => 123456789, 'is_bot' => true, 'username' => 'poson_test_bot', 'can_read_all_group_messages' => false]]),
            default => Http::response(['ok' => true, 'result' => true]),
        };
    }]);
    Page::factory()->system('offer')->create(['is_active' => true, 'needs_review' => false]);
    Page::factory()->system('privacy')->create(['is_active' => false, 'needs_review' => false]);

    expect(fn () => app(SetBotEnabled::class)->enable(staffUser(), '203.0.113.7'))
        ->toThrow(fn (TelegramBotException $refused) => expect($refused->reason)->toBe('not_ready')->and($refused->failing)->toBe(['privacy']));

    expect(BotSwitch::isEnabled())->toBeFalse();
});

// 8

it('keeps the lead text after an Admin deletes the thread, and nothing points back at it', function () {
    tcabTelegramOk();
    $admin = staffUser(Role::Admin);
    $chat = tcabThread();
    $lead = tcabRun($chat, $admin, [tcabInbound($chat, 'Keep this lead text')->id]);

    app(DeleteConversation::class)->handle($chat, $admin, null);

    expect(Lead::query()->find($lead->id)->message)->toBe('Keep this lead text')
        ->and(TelegramMessage::query()->where('lead_id', $lead->id)->count())->toBe(0)
        ->and(TelegramUser::query()->count())->toBe(0);

    Livewire::actingAs($admin)->test(LeadShow::class, ['lead' => $lead->fresh()])
        ->assertDontSeeHtml('data-lead-telegram')->assertDontSeeHtml('data-telegram-thread-link')
        ->assertDontSeeHtml(route('admin.telegram.chats.show', $chat->id))->assertSee('Keep this lead text');
});

// 9

it('gives 403 on a message\'s lead link to another manager, and the thread shows only the L-id', function () {
    $viewer = staffUser(Role::SalesManager);
    $owner = staffUser(Role::SalesManager);
    $chat = tcabThread();
    $lead = Lead::factory()->create(['manager_id' => $owner->id, 'company' => 'Leadprivate Secret Co', 'email' => 'leadprivate@example.test']);
    tcabInbound($chat, 'visible customer text')->forceFill(['lead_id' => $lead->id])->save();

    $page = Livewire::actingAs($viewer)->test(Show::class, ['telegramUser' => $chat])->assertSee($lead->reference());
    expect($page->html())->not->toContain('Leadprivate')->not->toContain('leadprivate@example.test')->not->toContain($lead->phone);

    $this->actingAs($viewer)->get(route('admin.leads.show', $lead))->assertForbidden();
});

// 10

it('refuses and makes no lead from a scrubbed row, and unban deletes it', function () {
    tcabTelegramOk();
    $admin = staffUser(Role::Admin);
    $chat = tcabThread(['last_message_at' => now()->subMonths(13)]);
    $message = tcabInbound($chat);
    $chat->forceFill(['banned_at' => now()->subMonths(13), 'banned_by_id' => $admin->id])->save();

    app(ScrubBannedTelegramUsers::class)->handle();

    expect(fn () => app(ConvertTelegramToLead::class)->handle($chat->fresh(), $admin, [$message->id], LeadType::Quote))
        ->toThrow(InboxRefused::class);
    expect(Lead::query()->count())->toBe(0);

    app(SetConversationBan::class)->unban($chat->fresh(), $admin, null);

    expect(TelegramUser::query()->whereKey($chat->id)->exists())->toBeFalse();
    expect(fn () => app(ConvertTelegramToLead::class)->handle($chat, $admin, [$message->id], LeadType::Quote))
        ->toThrow(ModelNotFoundException::class);
    expect(Lead::query()->count())->toBe(0);
});

// 11

it('refuses tampered convert state from the screen and makes no lead', function (Closure $tamper, string $reason) {
    $admin = staffUser(Role::Admin);
    $chat = tcabThread();
    $own = tcabInbound($chat);
    $otherChat = tcabThread();
    $foreign = tcabInbound($otherChat);
    $outbound = TelegramMessage::factory()->for($chat)->outbound($admin)->create(['text' => 'our reply']);
    [$property, $value] = $tamper(compact('own', 'foreign', 'outbound'));

    Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat])
        ->call('openConvert', $own->id)
        ->set($property, $value)
        ->call('convert')
        ->assertDispatched('toast', type: 'error', title: __('telegram_inbox.refused.'.$reason));

    expect(Lead::query()->count())->toBe(0)
        ->and(TelegramMessage::query()->whereNotNull('lead_id')->count())->toBe(0);
})->with([
    // The render drops ticks that are not convertible, so a tampered selection arrives empty.
    'another thread' => [fn (array $m) => ['convertSelected', [$m['foreign']->id]], 'nothing_selected'],
    'outbound' => [fn (array $m) => ['convertSelected', [$m['outbound']->id]], 'nothing_selected'],
    'nested array' => [fn (array $m) => ['convertSelected', [[$m['own']->id]]], 'nothing_selected'],
    'checkout type' => [fn (array $m) => ['convertType', 'checkout'], 'invalid_type'],
    'bogus type' => [fn (array $m) => ['convertType', 'bogus'], 'invalid_type'],
]);

it('refuses a foreign, outbound, nested or over-long selection in the action itself', function (Closure $ids, string $reason) {
    $admin = staffUser(Role::Admin);
    $chat = tcabThread();
    $own = tcabInbound($chat);
    $foreign = tcabInbound(tcabThread());
    $outbound = TelegramMessage::factory()->for($chat)->outbound($admin)->create(['text' => 'our reply']);
    $many = collect(range(1, ConvertTelegramToLead::MAX_MESSAGES + 1))->map(fn () => tcabInbound($chat)->id)->all();

    $selection = $ids(compact('own', 'foreign', 'outbound', 'many'));

    expect(fn () => tcabRun($chat, $admin, $selection))
        ->toThrow(fn (InboxRefused $refused) => expect($refused->reason)->toBe($reason));

    expect(Lead::query()->count())->toBe(0)
        ->and(TelegramMessage::query()->whereNotNull('lead_id')->count())->toBe(0);
})->with([
    'another thread' => [fn (array $m) => [$m['own']->id, $m['foreign']->id], 'invalid_selection'],
    'outbound' => [fn (array $m) => [$m['outbound']->id], 'invalid_selection'],
    'nested array' => [fn (array $m) => [[$m['own']->id]], 'invalid_selection'],
    'over 50 ids' => [fn (array $m) => $m['many'], 'too_many_selected'],
]);

// 12

it('ignores customerId and matchCustomer sent to the public lead form', function () {
    $matched = Customer::factory()->create(['phone' => '+998901112233']);
    $chosen = Customer::factory()->create(['phone' => '+998907776655']);

    $form = Livewire::test(LeadForm::class);
    foreach (['customerId' => $chosen->id, 'matchCustomer' => false, 'managerId' => 1, 'source' => 'telegram', 'type' => 'checkout'] as $property => $value) {
        try {
            $form->set($property, $value);
        } catch (Throwable) {
            // No such public property: refused.
        }
    }

    $form->set('name', 'Aziz')->set('phone', '+998901112233')->set('company', 'OOO Market');
    $this->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();
    $form->call('submit');

    $lead = Lead::query()->sole();
    expect($lead->customer_id)->toBe($matched->id)
        ->and($lead->source->value)->not->toBe('telegram')
        ->and($lead->manager_id)->not->toBe(1);

    $other = Livewire::test(LeadForm::class);
    try {
        $other->set('customerId', $chosen->id);
    } catch (Throwable) {
        // refused
    }
    $other->set('name', 'Aziz')->set('phone', '+998909998877')->set('company', 'OOO Market');
    $this->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();
    $other->call('submit');

    expect(Lead::query()->where('phone', '+998909998877')->sole()->customer_id)->toBeNull();
});
