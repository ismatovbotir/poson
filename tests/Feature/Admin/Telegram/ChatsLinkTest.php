<?php

use App\Actions\Telegram\InboxRefused;
use App\Actions\Telegram\LinkConversationToCustomer;
use App\Actions\Telegram\ScrubBannedTelegramUsers;
use App\Actions\Telegram\SetConversationBan;
use App\Actions\Telegram\VerifyConversationLink;
use App\Enums\ActivityType;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Enums\TelegramUserState;
use App\Livewire\Admin\Settings\Integrations;
use App\Livewire\Admin\Telegram\Bot;
use App\Livewire\Admin\Telegram\Chats\Index;
use App\Livewire\Admin\Telegram\Chats\Show;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\SettingsAudit;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Models\User;
use App\Support\Phone;
use App\Support\Telegram\Bot\LinkPhoneSignal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * Plan 21 A4: ban / unban, verify link, unlinked filter, manual link
 * (TG-35, T21-14, T21-15, T21-25).
 */
function linkManager(): User
{
    return staffUser(Role::SalesManager);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function linkThread(array $attributes = [], ?User $manager = null): TelegramUser
{
    $factory = TelegramUser::factory()->registered();

    return ($manager ? $factory->assigned($manager) : $factory)->create($attributes);
}

function linkRevoke(User $user, string $permission): User
{
    $user->roles->first()->revokePermissionTo($permission);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

function linkAuditRow(int $conversationId): ?SettingsAudit
{
    return SettingsAudit::query()->where('key', 'telegram.conversation.'.$conversationId)->first();
}

/* ------------------------------------------------------------------- ban */

it('refuses ban, unban, verify and link on another manager\'s thread by direct calls (TG-35)', function (string $method) {
    $owner = linkManager();
    $customer = Customer::factory()->create();
    $chat = linkThread(['customer_id' => $customer->id], $owner);
    $view = Livewire::actingAs($owner)->test(Show::class, ['telegramUser' => $chat]);

    $chat->forceFill(['manager_id' => linkManager()->id])->save();

    $view->call($method)->assertForbidden();

    expect($chat->fresh()->banned_at)->toBeNull()->and($chat->fresh()->link_verified_at)->toBeNull();
})->with(['ban', 'unban', 'verifyLink', 'link']);

it('refuses the ban and link actions on a foreign thread for a direct action call', function () {
    $user = linkManager();
    $customer = Customer::factory()->create();
    $chat = linkThread(['customer_id' => $customer->id], linkManager());
    $free = linkThread(manager: linkManager());

    expect(fn () => app(SetConversationBan::class)->ban($chat, $user, null))->toThrow(AuthorizationException::class);
    expect(fn () => app(SetConversationBan::class)->unban($chat, $user, null))->toThrow(AuthorizationException::class);
    expect(fn () => app(VerifyConversationLink::class)->handle($chat, $user, null))->toThrow(AuthorizationException::class);
    expect(fn () => app(LinkConversationToCustomer::class)->handle($free, $user, $customer->id))->toThrow(AuthorizationException::class);
});

it('bans by role', function (Role $role, bool $allowed) {
    $actor = staffUser($role);
    $chat = linkThread();
    $call = fn () => app(SetConversationBan::class)->ban($chat, $actor, '10.0.0.1');

    if ($allowed) {
        expect($call())->toBeTrue()
            ->and($chat->fresh()->isBanned())->toBeTrue()
            ->and($chat->fresh()->banned_by_id)->toBe($actor->id);
    } else {
        expect($call)->toThrow(AuthorizationException::class);
        expect($chat->fresh()->isBanned())->toBeFalse();
    }
})->with([
    'admin' => [Role::Admin, true],
    'sales manager' => [Role::SalesManager, true],
    'content manager' => [Role::ContentManager, false],
    'service engineer' => [Role::ServiceEngineer, false],
]);

it('refuses ban without telegram.ban and hides the button', function () {
    $user = linkRevoke(linkManager(), 'telegram.ban');
    $chat = linkThread(manager: $user);

    expect(fn () => app(SetConversationBan::class)->ban($chat, $user, null))->toThrow(AuthorizationException::class);
    Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat])->assertDontSeeHtml('data-action-ban');
});

it('writes audit and Activity rows on ban and unban, only on a real change, with no name', function () {
    $actor = staffUser(Role::Admin);
    $chat = linkThread(['name' => 'Secret Person']);
    $bans = app(SetConversationBan::class);

    expect($bans->ban($chat, $actor, '10.0.0.1'))->toBeTrue()
        ->and($bans->ban($chat, $actor, '10.0.0.1'))->toBeFalse();

    $audit = SettingsAudit::query()->where('key', 'telegram.conversation.'.$chat->id)->sole();
    expect($audit->action)->toBe(AuditAction::Banned)
        ->and($audit->area)->toBe(SettingsArea::TelegramInbox)
        ->and($audit->user_id)->toBe($actor->id)
        ->and($audit->ip)->toBe('10.0.0.1')
        ->and(json_encode($audit->toArray()))->not->toContain('Secret Person');

    expect($bans->unban($chat, $actor, null))->toBeTrue()
        ->and($chat->fresh()->banned_at)->toBeNull()
        ->and($chat->fresh()->banned_by_id)->toBeNull()
        ->and($bans->unban($chat, $actor, null))->toBeFalse();

    expect(SettingsAudit::query()->where('action', AuditAction::Unbanned)->count())->toBe(1)
        ->and($chat->activities()->orderBy('id')->pluck('meta')->all())->toBe([['action' => 'ban'], ['action' => 'unban']]);
});

it('bans and unbans through the screen, and the reply box follows', function () {
    telegramBotConfigured();
    $user = linkManager();
    $chat = linkThread(manager: $user);

    Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat])
        ->assertSeeHtml('data-action-ban')
        ->call('ban')
        ->assertSeeHtml('data-action-unban')
        ->assertSeeHtml('data-banner="banned"')
        ->call('unban')
        ->assertSeeHtml('data-action-ban')
        ->assertDontSeeHtml('data-banner="banned"');
});

it('has translated audit labels for the new actions', function (string $locale) {
    foreach ([AuditAction::Banned, AuditAction::Unbanned, AuditAction::Verified] as $action) {
        expect(__('enums.audit_action.'.$action->value, [], $locale))->not->toStartWith('enums.');
    }
})->with(['uz', 'ru', 'en']);

/* ---------------------------------------------------------------- verify */

it('lets a manager verify the link of their own or an unassigned customer', function (Closure $manager) {
    $user = linkManager();
    $customer = Customer::factory()->create(['manager_id' => $manager($user)]);
    $chat = linkThread(['customer_id' => $customer->id], $user);

    expect(app(VerifyConversationLink::class)->handle($chat, $user, '10.0.0.2'))->toBeTrue();

    $fresh = $chat->fresh();
    expect($fresh->isLinkVerified())->toBeTrue()
        ->and($fresh->link_verified_by_id)->toBe($user->id)
        ->and(linkAuditRow($chat->id)->action)->toBe(AuditAction::Verified);

    $activity = $chat->activities()->sole();
    expect($activity->meta)->toBe(['action' => 'verify_link', 'customer_id' => $customer->id, 'contact_id' => null, 'account_changed' => false, 'phone_signal' => 'no_match'])
        ->and(app(VerifyConversationLink::class)->handle($fresh, $user, null))->toBeFalse();
})->with([
    'own customer' => [fn (User $user) => $user->id],
    'unassigned customer' => [fn () => null],
]);

it('refuses verify for another manager\'s customer (T21-14)', function () {
    $user = linkManager();
    $customer = Customer::factory()->create(['manager_id' => linkManager()->id]);
    $chat = linkThread(['customer_id' => $customer->id], $user);

    expect(fn () => app(VerifyConversationLink::class)->handle($chat, $user, null))->toThrow(AuthorizationException::class);
    expect($chat->fresh()->link_verified_at)->toBeNull();

    Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat])->assertDontSeeHtml('data-verify-link');
});

it('refuses verify without customers.update', function () {
    $user = linkRevoke(linkManager(), 'customers.update');
    $customer = Customer::factory()->create(['manager_id' => null]);
    $chat = linkThread(['customer_id' => $customer->id], $user);

    expect($user->can('customers.update'))->toBeFalse();
    expect(fn () => app(VerifyConversationLink::class)->handle($chat, $user, null))->toThrow(AuthorizationException::class);
    expect(linkAuditRow($chat->id))->toBeNull();
});

it('refuses verify on an unregistered thread as Admin (T21-25)', function () {
    $admin = staffUser(Role::Admin);
    $chat = TelegramUser::factory()->unregistered()->create(['customer_id' => Customer::factory()->create()->id]);

    expect(fn () => app(VerifyConversationLink::class)->handle($chat, $admin, null))
        ->toThrow(fn (InboxRefused $refused) => expect($refused->reason)->toBe('unregistered'));
    expect($chat->fresh()->link_verified_at)->toBeNull();
});

it('refuses verify with no customer, for an Admin through the screen', function () {
    $admin = staffUser(Role::Admin);
    $chat = linkThread();

    expect(fn () => app(VerifyConversationLink::class)->handle($chat, $admin, null))
        ->toThrow(fn (InboxRefused $refused) => expect($refused->reason)->toBe('no_customer'));

    Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat])
        ->assertDontSeeHtml('data-verify-link')
        ->call('verifyLink')
        ->assertDispatched('toast', type: 'error');
    expect($chat->fresh()->link_verified_at)->toBeNull();
});

it('counts verified only with a customer (T21-15)', function () {
    $chat = TelegramUser::factory()->registered()->make();
    $chat->link_verified_at = now();

    expect($chat->isLinkVerified())->toBeFalse();

    $chat->customer_id = 5;
    expect($chat->isLinkVerified())->toBeTrue();
});

it('shows verified and unverified states and the banner', function () {
    $admin = staffUser(Role::Admin);
    $customer = Customer::factory()->create(['name' => 'Acme CRM']);
    $chat = linkThread(['customer_id' => $customer->id]);

    $component = Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat]);
    $component->assertSee('Acme CRM')->assertSeeHtml('data-banner="unverified"')->assertSeeHtml('data-link-unverified')->assertSeeHtml('data-verify-link');

    $component->call('verifyLink')->assertSeeHtml('data-link-verified')->assertDontSeeHtml('data-banner="unverified"')->assertSee($admin->name);
});

it('marks declared company and INN as unproven', function () {
    $chat = linkThread(['declared_company' => 'Claimed LLC', 'declared_inn' => '123456789']);

    $html = Livewire::actingAs(staffUser(Role::Admin))->test(Show::class, ['telegramUser' => $chat])->html();

    expect(substr_count($html, 'data-unproven='))->toBe(2);
    $none = Livewire::actingAs(staffUser(Role::Admin))->test(Show::class, ['telegramUser' => linkThread()]);
    $none->assertDontSeeHtml('data-unproven');
});

/* -------------------------------------------------------- unlinked filter */

it('filters unlinked registered threads inside visibility (§22)', function () {
    $sales = linkManager();
    linkThread(['name' => 'Free Unlinked']);
    linkThread(['name' => 'Linked One', 'customer_id' => Customer::factory()->create()->id]);
    linkThread(['name' => 'Foreign Unlinked'], linkManager());
    TelegramUser::factory()->unregistered()->create(['first_name' => 'Visitorname', 'last_name' => 'X']);

    Livewire::actingAs($sales)->test(Index::class)->call('setScope', 'unlinked')
        ->assertSet('scope', 'unlinked')
        ->assertSee('Free Unlinked')
        ->assertDontSee('Linked One')
        ->assertDontSee('Foreign Unlinked')
        ->assertSeeHtml('data-unlinked');

    Livewire::actingAs(staffUser(Role::Admin))->test(Index::class)->call('setScope', 'unlinked')
        ->assertSee('Foreign Unlinked')
        ->assertDontSee('Visitorname');
});

/* ------------------------------------------------------------ manual link */

it('links an unlinked thread by ids only, without verifying or touching the CRM (T21-14, T21-15)', function () {
    $user = linkManager();
    $customer = Customer::factory()->create(['manager_id' => $user->id, 'name' => 'Keep Name', 'phone' => '+998901110000']);
    $contact = Contact::factory()->create(['customer_id' => $customer->id, 'name' => 'Keep Contact', 'phone' => '+998901110001', 'telegram' => null]);
    $chat = linkThread(['phone' => '+998907770000', 'declared_company' => 'Other Name', 'username' => 'someuser'], $user);
    $before = [$customer->fresh()->toArray(), $contact->fresh()->toArray()];

    $this->travel(5)->minutes();

    expect(app(LinkConversationToCustomer::class)->handle($chat, $user, $customer->id, $contact->id))->toBeTrue();

    $fresh = $chat->fresh();
    expect($fresh->customer_id)->toBe($customer->id)
        ->and($fresh->contact_id)->toBe($contact->id)
        ->and($fresh->link_verified_at)->toBeNull()
        ->and($fresh->link_verified_by_id)->toBeNull()
        ->and($fresh->account_changed)->toBeFalse()
        ->and($customer->fresh()->toArray())->toBe($before[0])
        ->and($contact->fresh()->toArray())->toBe($before[1]);

    expect($chat->activities()->sole()->meta)->toBe([
        'action' => 'link', 'from_customer_id' => null, 'from_contact_id' => null, 'customer_id' => $customer->id, 'contact_id' => $contact->id, 'account_changed_before' => false, 'phone_signal' => 'no_match',
    ])->and($chat->activities()->sole()->type)->toBe(ActivityType::StatusChange);
});

it('refuses a manual link to another manager\'s customer (T21-14)', function () {
    $user = linkManager();
    $customer = Customer::factory()->create(['manager_id' => linkManager()->id]);
    $chat = linkThread(manager: $user);

    expect(fn () => app(LinkConversationToCustomer::class)->handle($chat, $user, $customer->id))->toThrow(AuthorizationException::class);
    expect($chat->fresh()->customer_id)->toBeNull();
});

it('refuses a manual link without customers.update', function () {
    $user = linkRevoke(linkManager(), 'customers.update');
    $customer = Customer::factory()->create(['manager_id' => null]);
    $chat = linkThread(manager: $user);

    expect(fn () => app(LinkConversationToCustomer::class)->handle($chat, $user, $customer->id))->toThrow(AuthorizationException::class);
    Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat])->assertDontSeeHtml('data-link-picker');
});

it('refuses a contact of a different customer, for an Admin too', function () {
    $admin = staffUser(Role::Admin);
    $customer = Customer::factory()->create();
    $foreignContact = Contact::factory()->create(['customer_id' => Customer::factory()->create()->id]);
    $chat = linkThread();

    expect(fn () => app(LinkConversationToCustomer::class)->handle($chat, $admin, $customer->id, $foreignContact->id))
        ->toThrow(fn (InboxRefused $refused) => expect($refused->reason)->toBe('invalid_contact'));
    expect($chat->fresh()->customer_id)->toBeNull();
});

it('refuses a missing customer', function () {
    $chat = linkThread();

    expect(fn () => app(LinkConversationToCustomer::class)->handle($chat, staffUser(Role::Admin), 999999))
        ->toThrow(fn (InboxRefused $refused) => expect($refused->reason)->toBe('invalid_customer'));
});

it('refuses a link on an unregistered thread as Admin (T21-25, T21-11)', function () {
    $admin = staffUser(Role::Admin);
    $chat = TelegramUser::factory()->unregistered()->create();

    expect(fn () => app(LinkConversationToCustomer::class)->handle($chat, $admin, Customer::factory()->create()->id))
        ->toThrow(fn (InboxRefused $refused) => expect($refused->reason)->toBe('unregistered'));
    expect($chat->fresh()->customer_id)->toBeNull();

    Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat])->assertDontSeeHtml('data-link-picker');
});

it('allows relink for an Admin only', function () {
    $user = linkManager();
    $old = Customer::factory()->create(['manager_id' => $user->id]);
    $new = Customer::factory()->create(['manager_id' => $user->id]);
    $chat = linkThread(['customer_id' => $old->id], $user);

    expect(fn () => app(LinkConversationToCustomer::class)->handle($chat, $user, $new->id))->toThrow(AuthorizationException::class);
    expect($chat->fresh()->customer_id)->toBe($old->id);
    Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat])->assertDontSeeHtml('data-link-picker');

    expect(app(LinkConversationToCustomer::class)->handle($chat, staffUser(Role::Admin), $new->id))->toBeTrue()
        ->and($chat->fresh()->customer_id)->toBe($new->id);
});

it('clears verification and recomputes account_changed on any link change (T21-15)', function () {
    $admin = staffUser(Role::Admin);
    $customer = Customer::factory()->create();
    $shared = Contact::factory()->create(['customer_id' => $customer->id]);
    $single = Contact::factory()->create(['customer_id' => $customer->id]);
    linkThread(['customer_id' => $customer->id, 'contact_id' => $shared->id]);
    $chat = linkThread(['customer_id' => $customer->id, 'link_verified_at' => null]);
    $chat->forceFill(['link_verified_at' => now(), 'link_verified_by_id' => $admin->id, 'account_changed' => false])->save();
    $action = app(LinkConversationToCustomer::class);

    expect($action->handle($chat, $admin, $customer->id, $shared->id))->toBeTrue();
    $fresh = $chat->fresh();
    expect($fresh->link_verified_at)->toBeNull()->and($fresh->link_verified_by_id)->toBeNull()->and($fresh->account_changed)->toBeTrue();

    $chat->fresh()->forceFill(['link_verified_at' => now(), 'link_verified_by_id' => $admin->id])->save();
    expect($action->handle($chat, $admin, $customer->id, $single->id))->toBeTrue();
    $fresh = $chat->fresh();
    expect($fresh->link_verified_at)->toBeNull()->and($fresh->account_changed)->toBeFalse();
});

it('keeps verification when nothing changes', function () {
    $admin = staffUser(Role::Admin);
    $customer = Customer::factory()->create();
    $chat = linkThread(['customer_id' => $customer->id]);
    $chat->forceFill(['link_verified_at' => now(), 'link_verified_by_id' => $admin->id])->save();

    expect(app(LinkConversationToCustomer::class)->handle($chat, $admin, $customer->id))->toBeFalse()
        ->and($chat->fresh()->link_verified_at)->not->toBeNull()
        ->and($chat->activities()->count())->toBe(0);
});

it('keeps the flag on another thread when one account is moved off the contact (T21-36)', function () {
    $admin = staffUser(Role::Admin);
    $customer = Customer::factory()->create();
    $contact = Contact::factory()->create(['customer_id' => $customer->id]);
    $other = Contact::factory()->create(['customer_id' => $customer->id]);
    $moved = linkThread(['customer_id' => $customer->id, 'contact_id' => $contact->id]);
    $flagged = linkThread(['customer_id' => $customer->id, 'contact_id' => $contact->id]);
    $flagged->forceFill(['account_changed' => true])->save();

    app(LinkConversationToCustomer::class)->handle($moved, $admin, $customer->id, $other->id);

    expect($flagged->fresh()->account_changed)->toBeTrue();
});

it('keeps account_changed on verify and records it in the Activity meta (T21-36)', function () {
    $admin = staffUser(Role::Admin);
    $customer = Customer::factory()->create(['phone' => '+998901234567']);
    $chat = linkThread(['customer_id' => $customer->id, 'phone' => '+998901234567']);
    $chat->forceFill(['account_changed' => true])->save();

    app(VerifyConversationLink::class)->handle($chat, $admin, null);

    expect($chat->fresh()->account_changed)->toBeTrue()
        ->and($chat->activities()->sole()->meta)->toMatchArray(['account_changed' => true, 'phone_signal' => 'matches']);
});

it('keeps enable and secret rows visible when a manager toggles a ban past the limit (T21-35)', function () {
    $sales = linkManager();
    $admin = staffUser(Role::Admin);
    SettingsAudit::record(SettingsArea::TelegramBot, 'telegram.bot_enabled', AuditAction::Enabled, $admin, null);
    SettingsAudit::record(SettingsArea::Integrations, 'payme.key', AuditAction::Updated, $admin, null);
    $chat = linkThread(manager: $sales);
    $bans = app(SetConversationBan::class);

    foreach (range(1, SetConversationBan::PER_HOUR) as $i) {
        $i % 2 === 1 ? $bans->ban($chat, $sales, null) : $bans->unban($chat, $sales, null);
    }

    expect(fn () => $bans->ban($chat, $sales, null))
        ->toThrow(fn (InboxRefused $refused) => expect($refused->reason)->toBe('too_many_actions'));
    expect($chat->fresh()->isBanned())->toBeFalse()
        ->and(SettingsAudit::query()->where('area', SettingsArea::TelegramInbox->value)->count())->toBe(SetConversationBan::PER_HOUR);

    Livewire::actingAs($admin)->test(Bot::class)->assertSee('telegram.bot_enabled')->assertDontSee('telegram.conversation.');
    Livewire::actingAs($admin)->test(Integrations::class)->assertSee('payme.key')->assertDontSee('telegram.conversation.');
});

it('uses no ban limit on a repeat that changes nothing', function () {
    $sales = linkManager();
    $chat = linkThread(manager: $sales);
    $bans = app(SetConversationBan::class);

    expect($bans->unban($chat, $sales, null))->toBeFalse()
        ->and(RateLimiter::attempts(SetConversationBan::LIMIT_KEY.$sales->id))->toBe(0);
});

it('links through the picker, offering only customers the actor may edit', function () {
    $user = linkManager();
    $own = Customer::factory()->create(['name' => 'Zebra Own', 'manager_id' => $user->id]);
    $foreign = Customer::factory()->create(['name' => 'Zebra Foreign', 'manager_id' => linkManager()->id]);
    $contact = Contact::factory()->create(['customer_id' => $own->id, 'name' => 'Own Contact']);
    $chat = linkThread(manager: $user);

    $component = Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat])
        ->assertSeeHtml('data-link-picker')
        ->set('linkOpen', true)
        ->set('customerSearch', 'zebra')
        ->assertSeeHtml('data-customer-result="'.$own->id.'"')
        ->assertDontSeeHtml('data-customer-result="'.$foreign->id.'"');

    $component->call('chooseCustomer', $foreign->id)->assertDontSeeHtml('data-chosen-customer')->assertDontSeeHtml('data-do-link');
    $component->call('link')->assertForbidden();
    expect($chat->fresh()->customer_id)->toBeNull();

    $component = Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat])
        ->set('linkOpen', true)
        ->call('chooseCustomer', $own->id)
        ->assertSeeHtml('data-chosen-customer')
        ->assertSee('Own Contact')
        ->set('linkContactId', $contact->id)
        ->call('link')
        ->assertDispatched('toast', type: 'success')
        ->assertSet('linkCustomerId', null);

    expect($chat->fresh()->customer_id)->toBe($own->id)->and($chat->fresh()->contact_id)->toBe($contact->id);
    $component->assertSeeHtml('data-banner="unverified"')->assertDontSeeHtml('data-link-picker');
});

it('reports an empty pick', function () {
    Livewire::actingAs(staffUser(Role::Admin))->test(Show::class, ['telegramUser' => linkThread()])
        ->call('link')
        ->assertDispatched('toast', type: 'error');
});

it('has no public way to send a phone or company with a link', function () {
    expect(array_map(fn (ReflectionParameter $parameter) => $parameter->getName(), (new ReflectionMethod(LinkConversationToCustomer::class, 'handle'))->getParameters()))
        ->toBe(['conversation', 'actor', 'customerId', 'contactId', 'ip']);
});

it('renders customer names escaped in the panel and picker', function () {
    $admin = staffUser(Role::Admin);
    $customer = Customer::factory()->create(['name' => '<img src=x onerror=alert(9)>']);
    $chat = linkThread(['customer_id' => $customer->id]);

    $html = Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat])->html();

    expect($html)->not->toContain('<img src=x')->toContain('&lt;img src=x');
});

/* ---------------------------------------------------------- phone signal */

it('computes the phone signal against the customer and contacts in order', function (Closure $scenario, string $signal) {
    $customer = Customer::factory()->create(['phone' => '+998901110000']);
    $mine = Contact::factory()->create(['customer_id' => $customer->id, 'phone' => '+998902220000']);
    $other = Contact::factory()->create(['customer_id' => $customer->id, 'phone' => '+998903330000']);

    [$phone, $chosen] = $scenario($mine, $other);

    expect(LinkPhoneSignal::for($phone, $customer, $chosen))->toBe($signal);
})->with([
    'phone on another contact, none chosen' => [fn ($mine, $other) => ['+998903330000', null], LinkPhoneSignal::MATCHES_OTHER],
    'phone on another contact, a different one chosen' => [fn ($mine, $other) => ['+998903330000', $mine], LinkPhoneSignal::MATCHES_OTHER],
    'customer phone, a different contact chosen' => [fn ($mine, $other) => ['+998901110000', $mine], LinkPhoneSignal::MATCHES_OTHER],
    'customer phone, none chosen' => [fn ($mine, $other) => ['+998901110000', null], LinkPhoneSignal::MATCHES],
    'chosen contact phone' => [fn ($mine, $other) => ['+998902220000', $mine], LinkPhoneSignal::MATCHES],
    'unknown phone' => [fn ($mine, $other) => ['+998904440000', $mine], LinkPhoneSignal::NO_MATCH],
    'anonymous' => [fn ($mine, $other) => ['+88812345678', null], LinkPhoneSignal::ANONYMOUS],
]);

it('does not query for the verify signal on a poll of a verified thread', function () {
    $admin = staffUser(Role::Admin);
    $customer = Customer::factory()->create(['phone' => '+998901110000']);
    $chat = linkThread(['customer_id' => $customer->id, 'phone' => '+998907770000']);
    $chat->forceFill(['link_verified_at' => now()])->save();
    $component = Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $component->call('refreshThread');
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries->filter(fn (string $sql) => str_contains($sql, '"contacts"')))->toBeEmpty();
    $component->assertDontSeeHtml('data-phone-signal');
});

/* ---------------------------------------------------------------- picker */

it('tells same-name customers apart by id and last 4 digits, without the full number', function () {
    $user = linkManager();
    $one = Customer::factory()->create(['name' => 'Twin Company', 'manager_id' => $user->id, 'phone' => '+998901111234']);
    $two = Customer::factory()->create(['name' => 'Twin Company', 'manager_id' => $user->id, 'phone' => '+998902225678']);
    $chat = linkThread(manager: $user);

    $component = Livewire::actingAs($user)->test(Show::class, ['telegramUser' => $chat])->set('linkOpen', true)->set('customerSearch', 'twin');

    $component->assertSee('#'.$one->id)->assertSee('#'.$two->id)->assertSee('••••1234')->assertSee('••••5678');

    $component->call('chooseCustomer', $two->id);
    $html = $component->html();
    expect(strip_tags(substr($html, (int) strpos($html, 'data-chosen-customer'))))->toContain('#'.$two->id.' · ••••5678');

    foreach ([$component->html(), json_encode($component->snapshot)] as $blob) {
        foreach (['998901111234', '998902225678', '901111234', '902225678', '(90) 111', '(90) 222'] as $full) {
            expect($blob)->not->toContain($full);
        }
    }

    expect(json_encode($component->snapshot))->not->toContain('1234')->not->toContain('5678');
});

it('masks nothing it cannot', function () {
    expect(Phone::maskLastFour(null))->toBeNull()
        ->and(Phone::maskLastFour('12'))->toBeNull()
        ->and(Phone::maskLastFour('+998901234567'))->toBe('••••4567');
});

/* ------------------------------------------------------------ unban delete */

it('deletes a scrubbed row on unban, keeps the audit row and redirects with no 404', function () {
    $admin = staffUser(Role::Admin);
    $row = TelegramUser::factory()->create(['state' => TelegramUserState::Language, 'registered_at' => null, 'banned_at' => now()]);

    Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $row])
        ->call('unban')
        ->assertRedirect(route('admin.telegram.chats'));

    expect(TelegramUser::query()->whereKey($row->id)->exists())->toBeFalse()
        ->and(linkAuditRow($row->id)?->action)->toBe(AuditAction::Unbanned);
});

it('keeps a registered row on unban', function () {
    $admin = staffUser(Role::Admin);
    $row = linkThread(['banned_at' => now()]);

    Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $row])->call('unban')->assertNoRedirect();

    expect($row->fresh()->isBanned())->toBeFalse()->and($row->fresh()->isRegistered())->toBeTrue();
});

it('never deletes a lead-linked thread on unban', function () {
    $admin = staffUser(Role::Admin);
    $row = TelegramUser::factory()->create(['state' => TelegramUserState::Language, 'registered_at' => null, 'banned_at' => now()]);
    $message = TelegramMessage::factory()->for($row)->create();
    $message->forceFill(['lead_id' => Lead::factory()->create()->id])->save();

    expect(app(SetConversationBan::class)->unban($row, $admin, null))->toBeTrue()
        ->and($row->fresh())->not->toBeNull()
        ->and($row->fresh()->isBanned())->toBeFalse();
});

it('does not let a Sales manager reach an unregistered row to unban it', function () {
    $sales = linkManager();
    $row = TelegramUser::factory()->create(['state' => TelegramUserState::Language, 'registered_at' => null, 'banned_at' => now()]);

    expect(fn () => app(SetConversationBan::class)->unban($row, $sales, null))->toThrow(AuthorizationException::class);
    $this->actingAs($sales)->get(route('admin.telegram.chats.show', $row))->assertForbidden();

    expect(TelegramUser::query()->whereKey($row->id)->exists())->toBeTrue();
});

it('exempts an Admin from the ban limit', function () {
    $admin = staffUser(Role::Admin);
    $chat = linkThread();
    $bans = app(SetConversationBan::class);

    foreach (range(1, SetConversationBan::PER_HOUR + 2) as $i) {
        expect($i % 2 === 1 ? $bans->ban($chat, $admin, null) : $bans->unban($chat, $admin, null))->toBeTrue();
    }
});

it('lets a scrubbed user come back as a new visitor after an unban, end to end', function () {
    $admin = staffUser(Role::Admin);
    $row = linkThread(['telegram_id' => 5550077, 'banned_at' => now()->subMonths(13), 'last_message_at' => now()->subMonths(13), 'phone' => '+998901112233']);
    TelegramUser::query()->whereKey($row->id)->update(['created_at' => now()->subMonths(14)]);

    expect(app(ScrubBannedTelegramUsers::class)->handle())->toBe(1);

    $scrubbed = $row->fresh();
    expect($scrubbed->state)->toBe(TelegramUserState::Language)
        ->and($scrubbed->isRegistered())->toBeFalse()
        ->and($scrubbed->isBanned())->toBeTrue()
        ->and($scrubbed->phone)->toBeNull();

    expect(app(SetConversationBan::class)->unban($scrubbed, $admin, null))->toBeTrue()
        ->and(TelegramUser::query()->where('telegram_id', 5550077)->exists())->toBeFalse();
});

it('keeps banned rows out of prunable()', function () {
    $old = now()->subMonths(13);
    $banned = TelegramUser::factory()->create(['banned_at' => $old]);
    $free = TelegramUser::factory()->create();
    TelegramUser::query()->whereKey([$banned->id, $free->id])->update(['created_at' => $old]);

    $ids = (new TelegramUser)->prunable()->pluck('id')->all();

    expect($ids)->toContain($free->id)->not->toContain($banned->id);
});
