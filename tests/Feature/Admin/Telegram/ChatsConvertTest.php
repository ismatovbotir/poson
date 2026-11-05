<?php

use App\Actions\Leads\CreateLead;
use App\Actions\Telegram\ConvertTelegramToLead;
use App\Actions\Telegram\InboxRefused;
use App\Actions\Telegram\ScrubBannedTelegramUsers;
use App\Data\Leads\LeadData;
use App\Enums\ActivityType;
use App\Enums\LeadSource;
use App\Enums\LeadType;
use App\Enums\PriceLevel;
use App\Enums\Role;
use App\Enums\TelegramForwardStatus;
use App\Enums\TelegramMessageKind;
use App\Events\LeadCreated;
use App\Listeners\SendLeadToTelegram;
use App\Livewire\Admin\Leads\Show as LeadShow;
use App\Livewire\Admin\Quotes\Form as QuoteForm;
use App\Livewire\Admin\Telegram\Chats\Show;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Models\User;
use App\Support\Telegram\Bot\LinkPhoneSignal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * Plan 21 B2: convert selected customer messages to a lead (T21-10, T21-19, T21-25).
 * SQLite takes no row locks, so "concurrent" here means the loser's view: a stale
 * model and a repeated call after the winner committed.
 */
function tconvThread(array $attributes = [], ?User $manager = null): TelegramUser
{
    $factory = TelegramUser::factory()->registered();

    return ($manager ? $factory->assigned($manager) : $factory)->create(['name' => 'Bot Person', ...$attributes]);
}

function tconvInbound(TelegramUser $chat, ?string $text = 'Need a scanner'): TelegramMessage
{
    return TelegramMessage::factory()->for($chat)->inbound()->create(['text' => $text]);
}

/**
 * @param  array<array-key, mixed>|null  $ids
 */
function tconvRun(TelegramUser $chat, User $actor, ?array $ids = null, LeadType $type = LeadType::Quote): ?Lead
{
    return app(ConvertTelegramToLead::class)->handle($chat, $actor, $ids ?? [tconvInbound($chat)->id], $type);
}

function tconvExpectRefused(TelegramUser $chat, User $actor, ?array $ids, string $reason, LeadType $type = LeadType::Quote): void
{
    expect(fn () => tconvRun($chat, $actor, $ids, $type))
        ->toThrow(fn (InboxRefused $refused) => expect($refused->reason)->toBe($reason));

    expect(Lead::query()->count())->toBe(0);
}

beforeEach(function () {
    telegramBotConfigured();
});

/* ------------------------------------------------------------- the lead */

it('makes one lead from the selected messages and links exactly those', function () {
    Event::fake([LeadCreated::class]);
    $manager = staffUser(Role::SalesManager);
    $chat = tconvThread(['declared_company' => 'OOO Bot', 'locale' => 'ru'], $manager);
    $first = tconvInbound($chat, 'First line');
    $other = tconvInbound($chat, 'Not selected');
    $second = tconvInbound($chat, 'Second line');

    $lead = tconvRun($chat, $manager, [(string) $second->id, $first->id, $first->id]);

    expect($lead->source)->toBe(LeadSource::Telegram)
        ->and($lead->type)->toBe(LeadType::Quote)
        ->and($lead->name)->toBe('Bot Person')
        ->and($lead->phone)->toBe('+998901234567')
        ->and($lead->company)->toBe('OOO Bot')
        ->and($lead->locale)->toBe('ru')
        ->and($lead->message)->toBe("First line\n\nSecond line")
        ->and($lead->created_by_id)->toBe($manager->id)
        ->and($first->fresh()->lead_id)->toBe($lead->id)
        ->and($second->fresh()->lead_id)->toBe($lead->id)
        ->and($other->fresh()->lead_id)->toBeNull();

    Event::assertDispatchedTimes(LeadCreated::class, 1);
});

it('cleans the text like other inbound text and keeps no media data in the lead', function () {
    $admin = staffUser();
    $chat = tconvThread();
    $file = TelegramMessage::factory()->for($chat)->inbound()->media(TelegramMessageKind::Document)->create(['text' => "Caption\u{202E} here\u{0007}"]);

    $lead = tconvRun($chat, $admin, [$file->id]);

    expect($lead->message)->toBe('Caption here')
        ->and($lead->message)->not->toContain('requisites.pdf');
});

it('shows lead text with markup escaped', function () {
    $admin = staffUser();
    $chat = tconvThread();
    $lead = tconvRun($chat, $admin, [tconvInbound($chat, '<script>alert(1)</script>')->id]);

    expect($lead->message)->toBe('<script>alert(1)</script>');

    Livewire::actingAs($admin)->test(LeadShow::class, ['lead' => $lead])
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;');
});

it('does not touch updated_at on the linked message, which dates a stuck file copy', function () {
    $admin = staffUser();
    $chat = tconvThread();
    $message = tconvInbound($chat);
    $old = now()->subHour()->startOfSecond();
    DB::table('telegram_messages')->where('id', $message->id)->update(['updated_at' => $old, 'forward_status' => TelegramForwardStatus::Sending->value]);

    tconvRun($chat, $admin, [$message->id]);

    expect($message->fresh()->updated_at->equalTo($old))->toBeTrue();
});

/* ---------------------------------------------------------- customer link */

/**
 * @param  array<string, mixed>  $thread
 */
function tconvLinked(Customer $customer, ?Contact $contact = null, array $thread = []): TelegramUser
{
    $chat = tconvThread($thread);
    $chat->forceFill(['customer_id' => $customer->id, 'contact_id' => $contact?->id])->save();

    return $chat;
}

it('does not put an unverified no_match link on the lead (T21-39)', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]])]);
    $admin = staffUser();
    $linked = Customer::factory()->create(['name' => 'Real Customer LLC', 'phone' => '+998907770000']);
    $contact = Contact::factory()->create(['customer_id' => $linked->id, 'phone' => '+998907770001']);
    $chat = tconvLinked($linked, $contact);

    $lead = tconvRun($chat, $admin);
    $meta = $chat->activities()->sole()->meta;
    $alert = collect(telegramApiCalls())->firstWhere('method', 'sendMessage')['data']['text'];

    expect($lead->customer_id)->toBeNull()
        ->and($meta['customer_attached'])->toBeFalse()
        ->and($meta['phone_signal'])->toBe(LinkPhoneSignal::NO_MATCH)
        ->and($meta['customer_id'])->toBe($linked->id)
        ->and($meta['contact_id'])->toBe($contact->id)
        ->and($meta['link_verified'])->toBeFalse()
        ->and($alert)->not->toContain('Real Customer LLC')
        ->and($chat->fresh()->customer_id)->toBe($linked->id);
});

it('attaches an unverified link when the phone agrees, never a phone match elsewhere (T21-39)', function (string $signal) {
    $admin = staffUser();
    Customer::factory()->create(['phone' => '+998901234567']);
    $linked = Customer::factory()->create(['phone' => $signal === LinkPhoneSignal::MATCHES ? '+998901234567' : '+998907770000']);

    if ($signal === LinkPhoneSignal::MATCHES_OTHER) {
        Contact::factory()->create(['customer_id' => $linked->id, 'phone' => '+998901234567']);
    }

    $chat = tconvLinked($linked);
    $lead = tconvRun($chat, $admin);
    $meta = $chat->activities()->sole()->meta;

    expect($lead->customer_id)->toBe($linked->id)
        ->and($meta['customer_attached'])->toBeTrue()
        ->and($meta['phone_signal'])->toBe($signal);
})->with([LinkPhoneSignal::MATCHES, LinkPhoneSignal::MATCHES_OTHER]);

it('attaches a verified link even with no_match (T21-39)', function () {
    $admin = staffUser();
    $linked = Customer::factory()->create(['phone' => '+998907770000']);
    $chat = tconvLinked($linked);
    $chat->forceFill(['link_verified_at' => now(), 'link_verified_by_id' => $admin->id])->save();

    $lead = tconvRun($chat, $admin);
    $meta = $chat->activities()->sole()->meta;

    expect($lead->customer_id)->toBe($linked->id)
        ->and($meta['customer_attached'])->toBeTrue()
        ->and($meta['phone_signal'])->toBe(LinkPhoneSignal::NO_MATCH)
        ->and($meta['link_verified'])->toBeTrue();
});

it('gives a no_match lead no quote path, customer or Dealer price level (T21-39)', function () {
    $admin = staffUser();
    $linked = Customer::factory()->priceLevel(PriceLevel::Dealer)->create(['phone' => '+998907770000']);
    $lead = tconvRun(tconvLinked($linked), $admin);

    expect(QuoteForm::canQuoteFromLead($admin, $lead))->toBeFalse();

    $form = Livewire::withQueryParams(['lead' => $lead->id])->actingAs($admin)->test(QuoteForm::class)
        ->assertSet('form.lead_id', '')
        ->assertSet('form.customer_id', '');

    expect($form->instance()->priceLevel())->toBe(PriceLevel::Retail);

    $this->actingAs($admin)->get(route('admin.quotes.create', ['lead' => $lead->id]))->assertOk()->assertDontSee($linked->name);
});

it('warns in the panel that the customer will not be attached, only while open', function () {
    $admin = staffUser();
    $chat = tconvLinked(Customer::factory()->create(['phone' => '+998907770000']));
    $message = tconvInbound($chat);

    Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat])
        ->assertDontSeeHtml('data-convert-no-customer')
        ->call('openConvert', $message->id)
        ->assertSeeHtml('data-convert-no-customer')
        ->assertSee(__('telegram_inbox.convert.customer_not_attached'))
        ->assertSee(__('telegram_inbox.convert.text_only'));
});

it('leaves an unlinked thread without a customer, even when the phone matches one', function () {
    $admin = staffUser();
    Customer::factory()->create(['phone' => '+998901234567']);
    $chat = tconvThread();

    expect(tconvRun($chat, $admin)->customer_id)->toBeNull()
        ->and($chat->fresh()->customer_id)->toBeNull();
});

it('still matches a customer by phone for the other channels', function () {
    $customer = Customer::factory()->create(['phone' => '+998901234567']);

    $lead = app(CreateLead::class)->handle(new LeadData(
        source: LeadSource::Site, type: LeadType::Callback, name: 'A', phone: '+998901234567', locale: 'uz',
    ));

    expect($lead->customer_id)->toBe($customer->id);
});

/* --------------------------------------------------------------- manager */

it('picks the lead manager from the thread, then the actor; an Admin leaves it unassigned', function () {
    $manager = staffUser(Role::SalesManager);
    $admin = staffUser();
    $ownThread = tconvThread([], $manager);
    $unassigned = tconvThread();
    $otherThread = tconvThread([], $manager);
    $adminUnassigned = tconvThread();
    $adminService = tconvThread([], $manager);

    expect(tconvRun($ownThread, $manager)->manager_id)->toBe($manager->id)
        ->and(tconvRun($unassigned, $manager)->manager_id)->toBe($manager->id)
        ->and(tconvRun($otherThread, $admin)->manager_id)->toBe($manager->id)
        ->and(tconvRun($adminUnassigned, $admin)->manager_id)->toBeNull()
        ->and(tconvRun($adminService, $admin, null, LeadType::ServiceRequest)->manager_id)->toBeNull();
});

/* -------------------------------------------------------------- refusals */

it('refuses an actor without the type permission, never an unassigned lead (Q7)', function () {
    $manager = staffUser(Role::SalesManager);
    $chat = tconvThread([], $manager);
    $message = tconvInbound($chat);

    expect(fn () => tconvRun($chat, $manager, [$message->id], LeadType::ServiceRequest))->toThrow(AuthorizationException::class);

    expect(Lead::query()->count())->toBe(0)
        ->and($message->fresh()->lead_id)->toBeNull()
        ->and($chat->activities()->count())->toBe(0);
});

it('refuses an actor without leads.create', function () {
    $manager = staffUser(Role::SalesManager);
    $manager->roles->first()->revokePermissionTo('leads.create');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $chat = tconvThread([], $manager);

    expect(fn () => tconvRun($chat, $manager->fresh()))->toThrow(AuthorizationException::class);
    expect(Lead::query()->count())->toBe(0);
});

it('refuses another manager\'s thread, also by a direct Livewire call', function () {
    $owner = staffUser(Role::SalesManager);
    $other = staffUser(Role::SalesManager);
    $chat = tconvThread([], $owner);
    $message = tconvInbound($chat);

    expect(fn () => tconvRun($chat, $other, [$message->id]))->toThrow(AuthorizationException::class);

    $screen = Livewire::actingAs($owner)->test(Show::class, ['telegramUser' => $chat])->set('convertSelected', [$message->id]);
    $chat->forceFill(['manager_id' => $other->id])->save();
    $screen->call('convert')->assertForbidden();

    expect(Lead::query()->count())->toBe(0);
});

it('refuses an unregistered visitor as Admin, inside the action (T21-25)', function () {
    $admin = staffUser();
    $chat = TelegramUser::factory()->unregistered()->create();

    tconvExpectRefused($chat, $admin, [tconvInbound($chat)->id], 'unregistered');
});

it('refuses checkout as a type, even for an Admin', function () {
    $admin = staffUser();
    $chat = tconvThread();

    tconvExpectRefused($chat, $admin, [tconvInbound($chat)->id], 'invalid_type', LeadType::Checkout);
});

it('never sends an anonymous +888 number to the CRM (TG-29)', function () {
    $admin = staffUser();
    $chat = tconvThread(['phone' => '+88812345678']);

    tconvExpectRefused($chat, $admin, [tconvInbound($chat)->id], 'anonymous_number');

    expect(DB::table('leads')->where('phone', '+88812345678')->exists())->toBeFalse();
});

it('refuses a thread with no phone', function () {
    $admin = staffUser();
    $chat = tconvThread(['phone' => null]);

    tconvExpectRefused($chat, $admin, [tconvInbound($chat)->id], 'no_phone');
});

it('loads messages through this thread and inbound only', function (string $case) {
    $admin = staffUser();
    $chat = tconvThread();
    $good = tconvInbound($chat);

    $bad = match ($case) {
        'another thread' => tconvInbound(tconvThread())->id,
        'outbound' => TelegramMessage::factory()->for($chat)->outbound($admin)->create()->id,
        'missing' => 99999999,
    };

    tconvExpectRefused($chat, $admin, [$good->id, $bad], 'invalid_selection');

    expect($good->fresh()->lead_id)->toBeNull();
})->with(['another thread', 'outbound', 'missing']);

it('type-checks the selected ids', function (mixed $bad) {
    $admin = staffUser();
    $chat = tconvThread();

    tconvExpectRefused($chat, $admin, [tconvInbound($chat)->id, $bad], 'invalid_selection');
})->with([
    'nested array' => [[1]],
    'text' => ['abc'],
    'sql' => ['1 or 1=1'],
    'float' => [1.5],
    'zero' => [0],
    'negative' => [-1],
    'null' => [null],
    'bool' => [true],
    'trailing newline' => ["1\n"],
    'huge' => [str_repeat('9', 40)],
]);

it('refuses an empty selection and caps its size before any message query', function () {
    $admin = staffUser();
    $chat = tconvThread();

    tconvExpectRefused($chat, $admin, [], 'nothing_selected');

    DB::enableQueryLog();
    tconvExpectRefused($chat, $admin, range(1, ConvertTelegramToLead::MAX_MESSAGES + 1), 'too_many_selected');

    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query): bool => str_contains($query, 'telegram_messages'))->all())->toBe([]);
});

it('refuses a message with no text left after cleaning', function (?string $text) {
    $admin = staffUser();
    $chat = tconvThread();
    $empty = TelegramMessage::factory()->for($chat)->inbound()->media(TelegramMessageKind::Photo)->create(['text' => $text]);

    tconvExpectRefused($chat, $admin, [$empty->id], 'no_text');

    expect($empty->fresh()->lead_id)->toBeNull();
})->with([null, "\u{200B}\u{202E}", '   ']);

/* --------------------------------------------------------------- only once */

it('converts once: a repeat or a stale concurrent call links nothing and makes no second lead', function () {
    Queue::fake();
    $admin = staffUser();
    $chat = tconvThread();
    $stale = TelegramUser::query()->findOrFail($chat->id);
    $message = tconvInbound($chat);

    $lead = tconvRun($chat, $admin, [$message->id]);

    expect(tconvRun($chat, $admin, [$message->id]))->toBeNull()
        ->and(tconvRun($stale, $admin, [$message->id]))->toBeNull()
        ->and(Lead::query()->count())->toBe(1)
        ->and($message->fresh()->lead_id)->toBe($lead->id)
        ->and($chat->activities()->count())->toBe(1);

    Queue::assertPushed(CallQueuedListener::class, 1);
    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === SendLeadToTelegram::class);
});

it('links nothing new when a selection overlaps a message already on a lead', function () {
    $admin = staffUser();
    $chat = tconvThread();
    $done = tconvInbound($chat);
    $fresh = tconvInbound($chat);

    $lead = tconvRun($chat, $admin, [$done->id]);

    expect(tconvRun($chat, $admin, [$done->id, $fresh->id]))->toBeNull()
        ->and($fresh->fresh()->lead_id)->toBeNull()
        ->and(Lead::query()->sole()->id)->toBe($lead->id);
});

it('queues the lead alert only after commit, and not on a rollback', function () {
    Queue::fake();
    $admin = staffUser();
    $chat = tconvThread();
    $message = tconvInbound($chat);

    try {
        DB::transaction(function () use ($chat, $admin, $message): void {
            tconvRun($chat, $admin, [$message->id]);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    Queue::assertNotPushed(CallQueuedListener::class);
    expect(Lead::query()->count())->toBe(0)->and($message->fresh()->lead_id)->toBeNull();
});

/* ---------------------------------------------------------------- timeline */

it('records an Activity with ids only and shows it in the history', function () {
    $admin = staffUser();
    $chat = tconvThread(['telegram_id' => 987654321012]);
    $message = tconvInbound($chat, 'secret thread text');
    $lead = tconvRun($chat, $admin, [$message->id]);

    $activity = $chat->activities()->sole();

    expect($activity->type)->toBe(ActivityType::StatusChange)
        ->and($activity->user_id)->toBe($admin->id)
        ->and($activity->meta)->toMatchArray(['action' => 'convert', 'lead_id' => $lead->id, 'lead_type' => 'quote', 'message_ids' => [$message->id]])
        ->and(json_encode($activity->meta))->not->toContain('secret thread text')->not->toContain('+99890')->not->toContain('987654321012')
        ->and($activity->body)->toBeNull();

    Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat])
        ->set('historyOpen', true)
        ->assertSee(__('telegram_inbox.history.convert', ['actor' => $admin->name, 'lead' => 'L-'.$lead->id]));
});

/* --------------------------------------------------------------- lead show */

it('shows the From Telegram block on the lead after a real convert, gated per viewer', function () {
    $owner = staffUser(Role::SalesManager);
    $other = staffUser(Role::SalesManager);
    $chat = tconvThread(['telegram_id' => 987654321012], $owner);
    $lead = tconvRun($chat, $owner);
    $lead->forceFill(['manager_id' => null])->save();

    $page = Livewire::actingAs($owner)->test(LeadShow::class, ['lead' => $lead])
        ->assertSeeHtml('data-lead-telegram')
        ->assertSeeHtml('data-telegram-thread-link')
        ->assertSeeHtml(route('admin.telegram.chats.show', $chat->id))
        ->assertSee('Bot Person');

    expect($page->html())->not->toContain('987654321012');

    Livewire::actingAs($other)->test(LeadShow::class, ['lead' => $lead->fresh()])
        ->assertSeeHtml('data-telegram-hidden')
        ->assertDontSeeHtml('data-telegram-thread-link');
});

/* ---------------------------------------------------------------- screen */

it('converts from the screen: per message, then ticking more, with the chosen type', function () {
    Queue::fake();
    $manager = staffUser(Role::SalesManager);
    $chat = tconvThread(['telegram_id' => 987654321012], $manager);
    $first = tconvInbound($chat, 'One');
    $second = tconvInbound($chat, 'Two');

    $screen = Livewire::actingAs($manager)->test(Show::class, ['telegramUser' => $chat])
        ->assertSeeHtml('data-convert-message="'.$first->id.'"')
        ->assertDontSeeHtml('data-convert-form')
        ->call('openConvert', $first->id)
        ->assertSet('convertOpen', true)
        ->assertSet('convertSelected', [$first->id])
        ->assertSeeHtml('data-convert-form')
        ->assertSeeHtml('data-convert-select="'.$second->id.'"')
        ->assertSee(LeadType::Quote->label())
        ->assertDontSee(LeadType::ServiceRequest->label())
        ->assertDontSee(LeadType::Checkout->label())
        ->set('convertSelected', [(string) $first->id, (string) $second->id])
        ->set('convertType', LeadType::Callback->value)
        ->call('convert')
        ->assertDispatched('toast', type: 'success')
        ->assertSet('convertOpen', false)
        ->assertSet('convertSelected', []);

    $lead = Lead::query()->sole();

    expect($lead->type)->toBe(LeadType::Callback)
        ->and($lead->message)->toBe("One\n\nTwo");

    $screen->assertSeeHtml('data-lead-ref="'.$lead->id.'"')
        ->assertDontSeeHtml('data-convert-message="'.$first->id.'"');

    expect(json_encode($screen->snapshot))->not->toContain('987654321012')->not->toContain('file_id');
});

it('tells a second tab the lead already exists', function () {
    $admin = staffUser();
    $chat = tconvThread();
    $message = tconvInbound($chat);

    $screen = Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat])->call('openConvert', $message->id);
    tconvRun($chat, $admin, [$message->id]);

    $screen->call('convert')->assertDispatched('toast', type: 'info', title: __('telegram_inbox.toasts.already_converted'));

    expect(Lead::query()->count())->toBe(1);
});

it('keeps the selection and shows a toast on a refusal from tampered state', function (string $property, mixed $value, string $reason) {
    $admin = staffUser();
    $chat = tconvThread();
    $message = tconvInbound($chat);

    Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat])
        ->call('openConvert', $message->id)
        ->set($property, $value)
        ->call('convert')
        ->assertDispatched('toast', type: 'error', title: __('telegram_inbox.refused.'.$reason))
        ->assertSet('convertOpen', true);

    expect(Lead::query()->count())->toBe(0);
})->with([
    'type' => ['convertType', 'nonsense', 'invalid_type'],
    'ids dropped at render' => ['convertSelected', ['abc'], 'nothing_selected'],
    'none' => ['convertSelected', [], 'nothing_selected'],
]);

it('holds the conversation id as locked state', function () {
    $admin = staffUser();
    $chat = tconvThread();

    expect(fn () => Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat])->set('conversationId', 1))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('offers no convert control to an unregistered thread or a +888 one', function () {
    $admin = staffUser();
    $unregistered = TelegramUser::factory()->unregistered()->create();
    tconvInbound($unregistered);
    $anonymous = tconvThread(['phone' => '+88812345678']);
    $message = tconvInbound($anonymous);

    Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $unregistered])
        ->assertDontSeeHtml('data-convert-panel')
        ->assertDontSeeHtml('data-convert-message');

    Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $anonymous])
        ->assertSeeHtml('data-convert-no-phone')
        ->assertDontSeeHtml('data-convert-toggle')
        ->assertDontSeeHtml('data-convert-message="'.$message->id.'"');
});

it('adds no query to the poll while the control is closed', function () {
    $manager = staffUser(Role::SalesManager);
    $chat = tconvThread([], $manager);
    tconvRun($chat, $manager);

    $screen = Livewire::actingAs($manager)->test(Show::class, ['telegramUser' => $chat]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $screen->call('refreshThread');
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries->filter(fn (string $query): bool => preg_match('/"(leads|activities)"/', $query) === 1)->all())->toBe([])
        ->and($queries)->not->toBeEmpty();
});

/* --------------------------------------------------------------- retention */

it('keeps a lead-linked thread through prune and scrub, also when banned after the convert', function () {
    $admin = staffUser();
    $silent = tconvThread(['last_message_at' => now()->subMonths(13)]);
    $silentMessage = tconvInbound($silent, 'kept silent');
    $banned = tconvThread(['last_message_at' => now()->subMonths(13)]);
    $bannedMessage = tconvInbound($banned, 'kept banned');
    $control = tconvThread(['last_message_at' => now()->subMonths(13)]);

    $silentLead = tconvRun($silent, $admin, [$silentMessage->id]);
    $bannedLead = tconvRun($banned, $admin, [$bannedMessage->id]);
    $banned->forceFill(['banned_at' => now()->subMonths(13), 'banned_by_id' => $admin->id])->save();

    Artisan::call('model:prune', ['--model' => [TelegramUser::class]]);
    app(ScrubBannedTelegramUsers::class)->handle();

    expect(TelegramUser::query()->whereKey($control->id)->exists())->toBeFalse()
        ->and($silent->fresh()->name)->toBe('Bot Person')
        ->and($silentMessage->fresh()->lead_id)->toBe($silentLead->id)
        ->and($banned->fresh()->phone)->toBe('+998901234567')
        ->and($banned->fresh()->registered_at)->toBeInstanceOf(Carbon::class)
        ->and($bannedMessage->fresh()->lead_id)->toBe($bannedLead->id)
        ->and(Activity::query()->where('subject_id', $silent->id)->where('subject_type', $silent->getMorphClass())->count())->toBe(1);
});

it('refuses a banned thread as Admin with no lead, and that row is still scrubbed (T21-40)', function () {
    $admin = staffUser();
    $chat = tconvThread(['last_message_at' => now()->subMonths(13)]);
    $chat->forceFill(['banned_at' => now()->subMonths(13), 'banned_by_id' => $admin->id])->save();
    $message = tconvInbound($chat);

    tconvExpectRefused($chat, $admin, [$message->id], 'banned');

    expect($message->fresh()->lead_id)->toBeNull();

    app(ScrubBannedTelegramUsers::class)->handle();

    expect($chat->fresh()->phone)->toBeNull()
        ->and($chat->fresh()->banned_at)->not->toBeNull()
        ->and(TelegramMessage::query()->whereKey($message->id)->exists())->toBeFalse();
});

it('hides the control on a banned thread and when the actor has no lead type', function () {
    $admin = staffUser();
    $banned = tconvThread();
    $banned->forceFill(['banned_at' => now(), 'banned_by_id' => $admin->id])->save();
    $bannedMessage = tconvInbound($banned);

    Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $banned])
        ->assertDontSeeHtml('data-convert-panel')
        ->assertDontSeeHtml('data-convert-message="'.$bannedMessage->id.'"');

    $manager = staffUser(Role::SalesManager);
    $manager->roles->first()->revokePermissionTo('leads.sales');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $chat = tconvThread();
    tconvInbound($chat);

    Livewire::actingAs($manager->fresh())->test(Show::class, ['telegramUser' => $chat])
        ->assertDontSeeHtml('data-convert-panel')
        ->assertDontSeeHtml('data-convert-message');
});

/* ------------------------------------------------------------- limit */

it('limits a manager to 30 converts an hour, atomically, with the over-limit call making nothing (T21-40)', function () {
    $manager = staffUser(Role::SalesManager);
    $chat = tconvThread([], $manager);
    RateLimiter::increment(ConvertTelegramToLead::LIMIT_KEY.$manager->id, 3600, ConvertTelegramToLead::PER_HOUR - 1);

    expect(tconvRun($chat, $manager))->toBeInstanceOf(Lead::class);

    $message = tconvInbound($chat);

    expect(fn () => tconvRun($chat, $manager, [$message->id]))
        ->toThrow(fn (InboxRefused $refused) => expect($refused->reason)->toBe('too_many_actions'));

    expect(Lead::query()->count())->toBe(1)
        ->and($message->fresh()->lead_id)->toBeNull();
});

it('does not limit an Admin', function () {
    $admin = staffUser();
    $chat = tconvThread();
    RateLimiter::increment(ConvertTelegramToLead::LIMIT_KEY.$admin->id, 3600, ConvertTelegramToLead::PER_HOUR + 5);

    expect(tconvRun($chat, $admin))->toBeInstanceOf(Lead::class);
});

it('uses nothing of the limit on a refused or repeated call', function () {
    $manager = staffUser(Role::SalesManager);
    $chat = tconvThread([], $manager);
    $key = ConvertTelegramToLead::LIMIT_KEY.$manager->id;
    $good = tconvInbound($chat);
    $empty = TelegramMessage::factory()->for($chat)->inbound()->media(TelegramMessageKind::Photo)->create(['text' => null]);

    foreach ([[$empty->id], ['abc'], [], [99999999]] as $ids) {
        try {
            tconvRun($chat, $manager, $ids);
        } catch (InboxRefused) {
        }
    }

    expect(RateLimiter::attempts($key))->toBe(0);

    tconvRun($chat, $manager, [$good->id]);

    expect(RateLimiter::attempts($key))->toBe(1)
        ->and(tconvRun($chat, $manager, [$good->id]))->toBeNull()
        ->and(RateLimiter::attempts($key))->toBe(1);
});

/* ------------------------------------------------------------- text limit */

it('refuses a selection longer than the lead keeps, linking nothing (T21-40)', function () {
    $manager = staffUser(Role::SalesManager);
    $chat = tconvThread([], $manager);
    $messages = collect(range(1, 3))->map(fn (): TelegramMessage => tconvInbound($chat, str_repeat('x', 2000)));

    tconvExpectRefused($chat, $manager, $messages->pluck('id')->all(), 'text_too_long');

    expect(TelegramMessage::query()->whereNotNull('lead_id')->count())->toBe(0)
        ->and(RateLimiter::attempts(ConvertTelegramToLead::LIMIT_KEY.$manager->id))->toBe(0);
});

it('keeps a selection of exactly the limit whole', function () {
    $admin = staffUser();
    $chat = tconvThread();
    $first = tconvInbound($chat, str_repeat('a', 2499));
    $second = tconvInbound($chat, str_repeat('b', 2499));

    expect(mb_strlen(tconvRun($chat, $admin, [$first->id, $second->id])->message))->toBe(CreateLead::MESSAGE_LIMIT);
});

it('shows the text length and disables the button when over', function () {
    $admin = staffUser();
    $chat = tconvThread();
    $messages = collect(range(1, 3))->map(fn (): TelegramMessage => tconvInbound($chat, str_repeat('x', 2000)));

    Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat])
        ->call('openConvert', $messages[0]->id)
        ->assertSee(__('telegram_inbox.convert.text_length', ['length' => 2000, 'max' => CreateLead::MESSAGE_LIMIT]))
        ->set('convertSelected', $messages->pluck('id')->all())
        ->assertSeeHtml('font-semibold text-danger')
        ->assertSee(__('telegram_inbox.convert.text_length', ['length' => 6004, 'max' => CreateLead::MESSAGE_LIMIT]))
        ->call('convert')
        ->assertDispatched('toast', type: 'error', title: __('telegram_inbox.refused.text_too_long'));

    expect(Lead::query()->count())->toBe(0);
});

/* ------------------------------------------------------------- ticks */

it('keeps ticks through the poll and drops those no longer convertible', function () {
    $admin = staffUser();
    $chat = tconvThread();
    $keep = tconvInbound($chat);
    $gone = tconvInbound($chat);

    $screen = Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat])
        ->call('openConvert', $keep->id)
        ->call('refreshThread')
        ->assertSet('convertSelected', [$keep->id])
        ->set('convertSelected', [$keep->id, $gone->id])
        ->assertSet('convertSelected', [$keep->id, $gone->id]);

    tconvRun($chat, $admin, [$gone->id]);

    $screen->call('refreshThread')->assertSet('convertSelected', [$keep->id]);

    $screen->set('convertSelected', [$keep->id, 'abc', [1], 99999999])->assertSet('convertSelected', [$keep->id]);
});

it('enforces the cap on the screen and disables further checkboxes', function () {
    $admin = staffUser();
    $chat = tconvThread();
    $ids = collect(range(1, ConvertTelegramToLead::MAX_MESSAGES + 1))->map(fn (): int => tconvInbound($chat, 'x')->id);

    $screen = Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat])
        ->call('loadOlder')
        ->call('openConvert', $ids[0])
        ->set('convertSelected', $ids->all());

    expect($screen->get('convertSelected'))->toHaveCount(ConvertTelegramToLead::MAX_MESSAGES)
        ->and($screen->html())->toMatch('/<input[^>]*disabled[^>]*data-convert-select="'.$ids->last().'"/');
});

it('warns of a lead from this chat in the duplicate window, only while open', function () {
    $admin = staffUser();
    $chat = tconvThread();
    $lead = tconvRun($chat, $admin);
    $next = tconvInbound($chat);

    $screen = Livewire::actingAs($admin)->test(Show::class, ['telegramUser' => $chat])
        ->assertDontSeeHtml('data-convert-recent-lead')
        ->call('openConvert', $next->id)
        ->assertSeeHtml('data-convert-recent-lead');

    $lead->forceFill(['created_at' => now()->subDays(Lead::DUPLICATE_WINDOW_DAYS + 1)])->save();

    $screen->call('refreshThread')->assertDontSeeHtml('data-convert-recent-lead');
});

/* ------------------------------------------------------------- real queue */

it('sends the lead alert once through the real listener, as one flat comment line', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]])]);
    $admin = staffUser();
    $chat = tconvThread();

    $lead = tconvRun($chat, $admin, [tconvInbound($chat, "Need a scanner\nCustomer: Fake LLC")->id]);
    tconvRun($chat, $admin, [$chat->messages()->sole()->id]);

    $sent = collect(telegramApiCalls())->where('method', 'sendMessage');

    expect($sent)->toHaveCount(1)
        ->and($sent->first()['data']['text'])->toContain('Need a scanner Customer: Fake LLC')
        ->and($lead->activities()->where('type', ActivityType::Notification)->count())->toBe(1);
});

/* ------------------------------------------------------------- translations */

it('has every convert string in uz, ru and en', function (string $key) {
    foreach (['uz', 'ru', 'en'] as $locale) {
        expect(Lang::hasForLocale('telegram_inbox.'.$key, $locale))->toBeTrue($locale.': '.$key);
    }
})->with([
    'convert.title', 'convert.to_lead', 'convert.hint', 'convert.type', 'convert.selected', 'convert.select_message',
    'convert.submit', 'convert.cancel', 'convert.no_phone', 'convert.text_only', 'convert.text_length',
    'convert.customer_not_attached', 'convert.recent_lead', 'refused.text_too_long',
    'refused.nothing_selected', 'refused.too_many_selected', 'refused.invalid_selection', 'refused.invalid_type',
    'refused.no_text', 'refused.no_phone', 'refused.anonymous_number',
    'toasts.converted', 'toasts.already_converted', 'history.convert',
]);
