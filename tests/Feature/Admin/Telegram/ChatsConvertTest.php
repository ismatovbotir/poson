<?php

use App\Actions\Leads\CreateLead;
use App\Actions\Telegram\ConvertTelegramToLead;
use App\Actions\Telegram\InboxRefused;
use App\Actions\Telegram\ScrubBannedTelegramUsers;
use App\Data\Leads\LeadData;
use App\Enums\ActivityType;
use App\Enums\LeadSource;
use App\Enums\LeadType;
use App\Enums\Role;
use App\Enums\TelegramForwardStatus;
use App\Enums\TelegramMessageKind;
use App\Events\LeadCreated;
use App\Listeners\SendLeadToTelegram;
use App\Livewire\Admin\Leads\Show as LeadShow;
use App\Livewire\Admin\Telegram\Chats\Show;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Queue;
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

it('takes the customer from the thread link, not from a phone match', function () {
    $admin = staffUser();
    Customer::factory()->create(['phone' => '+998901234567']);
    $linked = Customer::factory()->create(['phone' => '+998907770000']);
    $contact = Contact::factory()->create(['customer_id' => $linked->id]);
    $chat = tconvThread();
    $chat->forceFill(['customer_id' => $linked->id, 'contact_id' => $contact->id])->save();

    $lead = tconvRun($chat, $admin);
    $activity = $chat->activities()->sole();

    expect($lead->customer_id)->toBe($linked->id)
        ->and($activity->meta['customer_id'])->toBe($linked->id)
        ->and($activity->meta['contact_id'])->toBe($contact->id)
        ->and($activity->meta['link_verified'])->toBeFalse();
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
    'ids' => ['convertSelected', ['abc'], 'invalid_selection'],
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

it('keeps a lead-linked thread through prune and scrub after a convert', function () {
    $admin = staffUser();
    $silent = tconvThread(['last_message_at' => now()->subMonths(13)]);
    $silentMessage = tconvInbound($silent, 'kept silent');
    $banned = tconvThread(['last_message_at' => now()->subMonths(13)]);
    $bannedMessage = tconvInbound($banned, 'kept banned');
    $banned->forceFill(['banned_at' => now()->subMonths(13), 'banned_by_id' => $admin->id])->save();
    $control = tconvThread(['last_message_at' => now()->subMonths(13)]);

    $silentLead = tconvRun($silent, $admin, [$silentMessage->id]);
    $bannedLead = tconvRun($banned, $admin, [$bannedMessage->id]);
    TelegramUser::query()->whereKey([$silent->id, $banned->id])->update(['last_message_at' => now()->subMonths(13)]);

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

/* ------------------------------------------------------------- translations */

it('has every convert string in uz, ru and en', function (string $key) {
    foreach (['uz', 'ru', 'en'] as $locale) {
        expect(Lang::hasForLocale('telegram_inbox.'.$key, $locale))->toBeTrue($locale.': '.$key);
    }
})->with([
    'convert.title', 'convert.to_lead', 'convert.hint', 'convert.type', 'convert.selected', 'convert.select_message',
    'convert.submit', 'convert.cancel', 'convert.no_phone',
    'refused.nothing_selected', 'refused.too_many_selected', 'refused.invalid_selection', 'refused.invalid_type',
    'refused.no_text', 'refused.no_phone', 'refused.anonymous_number',
    'toasts.converted', 'toasts.already_converted', 'history.convert',
]);
