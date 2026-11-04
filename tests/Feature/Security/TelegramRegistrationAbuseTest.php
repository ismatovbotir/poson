<?php

use App\Actions\Customers\SaveCustomer;
use App\Actions\Telegram\HandleTelegramUpdate;
use App\Data\Customers\CustomerData;
use App\Enums\CustomerType;
use App\Enums\LeadSource;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Enums\TelegramDirection;
use App\Enums\TelegramMessageKind;
use App\Enums\TelegramMessageStatus;
use App\Enums\TelegramUserState;
use App\Jobs\AnswerBotCallback;
use App\Jobs\DeliverBotMessage;
use App\Jobs\SendBotPrompt;
use App\Livewire\Admin\Customers\Form as CustomerFormScreen;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Telegram\Bot\BotLimits;
use App\Support\Telegram\Bot\BotSwitch;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/**
 * Plan 14 v2 B3 abuse cases 1-17 (security-reviewer). Happy paths live in
 * tests/Feature/Telegram/*; here only the hostile angles. Bot API faked,
 * fake token only, queue sync (replies run after the update commits).
 */
const ABZ_USER = 5560001;

const ABZ_PHONE = '+998901234567';

function abzBotOn(bool $on = true): void
{
    Setting::query()->updateOrCreate(['key' => BotSwitch::KEY], ['group' => SettingsArea::TelegramBot->group(), 'value' => $on]);
}

function abzNextId(): int
{
    static $id = 700_000;

    return ++$id;
}

/**
 * @param  array<string, mixed>  $message
 */
function abzMessage(array $message, int $userId = ABZ_USER): string
{
    $update = telegramPrivateUpdate(abzNextId(), $message, $userId);
    $update['message']['from'] = array_merge($update['message']['from'], ['username' => 'abuse_user', 'last_name' => 'Valiyev']);

    if (! array_key_exists('text', $message)) {
        unset($update['message']['text']);
    }

    return app(HandleTelegramUpdate::class)->handle($update);
}

function abzText(string $text, int $userId = ABZ_USER): string
{
    return abzMessage(['text' => $text], $userId);
}

function abzCallback(string $data, int $userId = ABZ_USER): string
{
    return app(HandleTelegramUpdate::class)->handle([
        'update_id' => abzNextId(),
        'callback_query' => [
            'id' => 'cbq-'.abzNextId(),
            'from' => ['id' => $userId, 'is_bot' => false, 'first_name' => 'Ali', 'last_name' => 'Valiyev', 'username' => 'abuse_user'],
            'message' => ['message_id' => 77, 'chat' => ['id' => $userId, 'type' => 'private']],
            'data' => $data,
        ],
    ]);
}

/**
 * @param  array<string, mixed>  $extra  Merged into the message (forward_origin ...)
 * @param  array<string, mixed>  $contactExtra  Merged into the contact (vcard ...)
 */
function abzContact(?string $phone, ?int $contactUserId = ABZ_USER, int $userId = ABZ_USER, array $extra = [], array $contactExtra = []): string
{
    $contact = array_filter(array_merge(['phone_number' => $phone, 'first_name' => 'Ali', 'user_id' => $contactUserId], $contactExtra), fn ($value) => $value !== null);

    return abzMessage(array_merge(['contact' => $contact], $extra), $userId);
}

function abzRegister(string $phone, ?string $company = null, ?string $inn = null, int $userId = ABZ_USER): TelegramUser
{
    abzText('/start', $userId);
    abzCallback('lang:en', $userId);
    abzContact($phone, $userId, $userId);
    abzCallback('name:yes', $userId);

    if ($company === null) {
        abzCallback('skip:company', $userId);
    } else {
        abzText($company, $userId);
        $inn === null ? abzCallback('skip:inn', $userId) : abzText($inn, $userId);
    }

    return abzUser($userId);
}

function abzUser(int $userId = ABZ_USER): TelegramUser
{
    return TelegramUser::query()->where('telegram_id', $userId)->sole();
}

/**
 * @return list<array<string, mixed>>
 */
function abzSent(?int $chatId = null): array
{
    return collect(telegramApiCalls())
        ->where('method', 'sendMessage')
        ->filter(fn (array $call) => $chatId === null || $call['data']['chat_id'] === (string) $chatId)
        ->pluck('data')->values()->all();
}

/**
 * Replace the default Bot API fake (the first registered stub always wins).
 *
 * @param  array<string, mixed>  $stubs
 */
function abzHttp(array $stubs): void
{
    Http::swap(new HttpFactory);
    Http::fake($stubs);
}

/**
 * @return Closure(): list<string> JSON-encoded log records so far
 */
function abzCaptureLogs(): Closure
{
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $exception = $event->context['exception'] ?? null;
        $logged[] = json_encode([$event->message, array_filter($event->context, fn ($value) => ! $value instanceof Throwable)])
            .($exception instanceof Throwable ? $exception->getMessage().get_class($exception) : '');
    });

    return function () use (&$logged): array {
        return $logged;
    };
}

/**
 * The prompts queued for one conversation, without per-user / per-update ids.
 *
 * @return list<array{prompts: list<string>, keyboard: ?string, clear: ?int, answersCallback: bool}>
 */
function abzPromptTrail(int $telegramId): array
{
    $id = abzUser($telegramId)->id;

    return Bus::dispatched(SendBotPrompt::class)
        ->filter(fn (SendBotPrompt $job) => $job->telegramUserId === $id)
        ->map(fn (SendBotPrompt $job) => ['prompts' => $job->prompts, 'keyboard' => $job->keyboard, 'clear' => $job->clearMarkupMessageId, 'answersCallback' => $job->callbackQueryId !== null])
        ->values()->all();
}

beforeEach(function () {
    telegramBotConfigured();
    abzBotOn();
    RateLimiter::clear(BotLimits::NEW_USERS_KEY);
    RateLimiter::clear(BotLimits::CUSTOMERS_KEY);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 900]])]);
});

// 1. A +888 number never reaches or matches the CRM.
it('case 1: keeps a +888 number on telegram_users only and gives every +888 user a separate customer', function () {
    $first = abzRegister('88801234567');
    $second = abzRegister('88801234567', userId: ABZ_USER + 1);

    expect($first->phone)->toBe('+88801234567')
        ->and($second->phone)->toBe('+88801234567')
        ->and($first->customer->phone)->toBeNull()
        ->and($first->contact->phone)->toBeNull()
        ->and($second->customer->phone)->toBeNull()
        ->and($second->contact->phone)->toBeNull()
        ->and($second->customer_id)->not->toBe($first->customer_id)
        ->and($second->contact_id)->not->toBe($first->contact_id)
        ->and($second->account_changed)->toBeFalse()
        ->and(Customer::query()->where('phone', 'like', '%888%')->count())->toBe(0)
        ->and(Contact::query()->where('phone', 'like', '%888%')->count())->toBe(0);
});

// 2. Only the sender's own, non-forwarded contact is accepted.
it('case 2: rejects a foreign, forwarded or user-less contact and stores no phone', function (Closure $send) {
    TelegramUser::factory()->unregistered()->create(['telegram_id' => ABZ_USER, 'locale' => 'en']);

    $send();

    $user = abzUser();

    expect($user->state)->toBe(TelegramUserState::Contact)
        ->and($user->phone)->toBeNull()
        ->and($user->consent_at)->toBeNull()
        ->and($user->customer_id)->toBeNull()
        ->and(Customer::query()->count())->toBe(0);
})->with([
    'user_id of another account' => [fn () => abzContact(ABZ_PHONE, 9990001)],
    'forwarded own contact' => [fn () => abzContact(ABZ_PHONE, ABZ_USER, extra: ['forward_origin' => ['type' => 'user', 'date' => 1]])],
    'forwarded foreign contact' => [fn () => abzContact(ABZ_PHONE, 9990001, extra: ['forward_origin' => ['type' => 'user', 'date' => 1]])],
    'no user_id' => [fn () => abzContact(ABZ_PHONE, null)],
]);

// 3. A late contact cannot change the phone.
it('case 3: ignores a contact sent in Name, Company or Registered state', function (TelegramUserState $state) {
    TelegramUser::factory()->create(['telegram_id' => ABZ_USER, 'locale' => 'en', 'state' => $state, 'name' => 'Ali', 'phone' => ABZ_PHONE, 'registered_at' => $state === TelegramUserState::Registered ? now() : null]);

    abzContact('+998935556677');

    expect(abzUser()->phone)->toBe(ABZ_PHONE)
        ->and(abzUser()->state)->toBe($state);
})->with([TelegramUserState::Name, TelegramUserState::Company, TelegramUserState::Registered]);

// 4. vcard is attacker-controlled text.
it('case 4: uses only contact.phone_number and never a phone inside a forged vcard', function () {
    $victim = Customer::factory()->create(['phone' => '+998935550000']);
    TelegramUser::factory()->unregistered()->create(['telegram_id' => ABZ_USER, 'locale' => 'en']);

    abzContact(ABZ_PHONE, contactExtra: ['vcard' => "BEGIN:VCARD\nVERSION:3.0\nTEL;TYPE=CELL:+998935550000\nEND:VCARD"]);

    expect(abzUser()->phone)->toBe(ABZ_PHONE);

    abzCallback('name:yes');
    abzCallback('skip:company');

    expect(abzUser()->customer_id)->not->toBe($victim->id)
        ->and($victim->fresh()->contacts)->toHaveCount(0);
});

// 5. No oracle: known, unknown and capped phones get the same replies.
it('case 5: queues byte-identical replies for a known phone, an unknown phone and the capped case', function () {
    Bus::fake();
    $known = Customer::factory()->create(['phone' => ABZ_PHONE, 'inn' => '301234567', 'name' => 'Known Customer']);
    Contact::factory()->for($known)->create(['phone' => ABZ_PHONE]);

    abzRegister(ABZ_PHONE, 'Market MChJ', '301234567', ABZ_USER);
    abzRegister('+998935557788', 'Market MChJ', '309999999', ABZ_USER + 1);

    foreach (range(1, BotLimits::CUSTOMERS_PER_HOUR) as $i) {
        RateLimiter::hit(BotLimits::CUSTOMERS_KEY, 3600);
    }
    abzRegister('+998935558899', 'Market MChJ', '308888888', ABZ_USER + 2);

    $trail = abzPromptTrail(ABZ_USER);

    expect($trail)->not->toBeEmpty()
        ->and(abzUser(ABZ_USER)->customer_id)->toBe($known->id)
        ->and(abzUser(ABZ_USER + 1)->customer_id)->not->toBeNull()->not->toBe($known->id)
        ->and(abzUser(ABZ_USER + 2)->customer_id)->toBeNull()
        ->and(abzPromptTrail(ABZ_USER + 1))->toBe($trail)
        ->and(abzPromptTrail(ABZ_USER + 2))->toBe($trail)
        ->and(serialize(abzPromptTrail(ABZ_USER + 2)))->toBe(serialize($trail));
});

// 6. Matching a customer never edits CRM data.
it('case 6: leaves name, INN, manager and existing contacts.telegram of a matched customer unchanged', function () {
    $manager = staffUser(Role::SalesManager);
    $customer = Customer::factory()->create(['phone' => ABZ_PHONE, 'inn' => '301234567', 'name' => 'Old Name LLC', 'manager_id' => $manager->id]);
    $taken = Contact::factory()->for($customer)->create(['phone' => ABZ_PHONE, 'name' => 'Boss', 'telegram' => '@boss_real']);
    $empty = Contact::factory()->for($customer)->create(['phone' => '+998935550000', 'name' => 'Clerk', 'telegram' => null]);
    $before = $customer->fresh()->only(['name', 'inn', 'manager_id', 'phone', 'email', 'type', 'source']);

    $user = abzRegister(ABZ_PHONE, 'Attacker Co', '309999999');

    expect($user->customer_id)->toBe($customer->id)
        ->and($customer->fresh()->only(array_keys($before)))->toEqual($before)
        ->and($taken->fresh()->telegram)->toBe('@boss_real')
        ->and($taken->fresh()->name)->toBe('Boss')
        ->and($empty->fresh()->telegram)->toBeNull()
        ->and(Customer::query()->count())->toBe(1);

    // contacts.telegram is filled only when it was empty.
    $filled = Contact::factory()->for(Customer::factory()->create())->create(['phone' => '+998977778899', 'telegram' => null]);

    abzRegister('+998977778899', userId: ABZ_USER + 1);

    expect($filled->fresh()->telegram)->toBe('@abuse_user');
});

// 7. A declared INN is a claim, never a key.
it('case 7: never links or copies an INN that another customer owns', function () {
    $owner = Customer::factory()->create(['inn' => '301234567', 'phone' => '+998711112233', 'name' => 'Owner LLC']);

    $user = abzRegister(ABZ_PHONE, 'Market MChJ', '301234567');

    expect($user->customer_id)->not->toBe($owner->id)
        ->and($user->customer->inn)->toBeNull()
        ->and($user->declared_inn)->toBe('301234567')
        ->and($owner->fresh()->contacts)->toHaveCount(0)
        ->and(Customer::query()->where('inn', '301234567')->sole()->id)->toBe($owner->id);
});

it('case 7: catches a race on the INN unique index and still completes the registration', function () {
    $raced = false;
    Customer::creating(function (Customer $customer) use (&$raced): void {
        if (! $raced && $customer->inn === '301234567') {
            $raced = true;
            Customer::withoutEvents(fn () => Customer::factory()->create(['inn' => '301234567', 'phone' => '+998711112233']));
        }
    });

    $user = abzRegister(ABZ_PHONE, 'Market MChJ', '301234567');

    expect($raced)->toBeTrue()
        ->and($user->state)->toBe(TelegramUserState::Registered)
        ->and($user->customer_id)->not->toBeNull()
        ->and($user->customer->inn)->toBeNull()
        ->and($user->customer->source)->toBe(LeadSource::Telegram)
        ->and($user->contact_id)->not->toBeNull()
        ->and($user->declared_inn)->toBe('301234567')
        ->and(collect(abzSent(ABZ_USER))->last()['text'])->toBe(__('telegram_bot.welcome', ['name' => 'Ali Valiyev'], 'en'));
});

// 8. Per-user limits, callbacks included, one notice per window.
it('case 8: drops the 21st update of a minute (callbacks included) with exactly one rate_limited notice', function () {
    TelegramUser::factory()->unregistered()->create(['telegram_id' => ABZ_USER, 'locale' => 'en']);

    foreach (range(1, 10) as $i) {
        abzText('hello '.$i);
        abzCallback('skip:all');
    }

    $beforeDrop = count(abzSent());
    abzCallback('skip:all');
    abzText('21st');
    abzCallback('skip:all');
    abzText('23rd');

    $notices = collect(abzSent())->where('text', __('telegram_bot.rate_limited', [], 'en'));

    expect($beforeDrop)->toBe(10)
        ->and($notices)->toHaveCount(1)
        ->and(collect(abzSent())->where('text', __('telegram_bot.contact.use_button', [], 'en')))->toHaveCount(10)
        ->and(abzUser()->state)->toBe(TelegramUserState::Contact);
});

it('case 8: drops the 301st update of a day with exactly one notice', function () {
    TelegramUser::factory()->unregistered()->create(['telegram_id' => ABZ_USER, 'locale' => 'en']);

    foreach (range(1, BotLimits::PER_DAY) as $i) {
        RateLimiter::hit(BotLimits::userKey(ABZ_USER, 'day'), 86400);
    }

    abzCallback('skip:all');
    abzText('again');
    $this->travel(2)->minutes();
    abzText('and again');

    expect(collect(abzSent())->pluck('text')->all())->toBe([__('telegram_bot.rate_limited', [], 'en')])
        ->and(abzUser()->state)->toBe(TelegramUserState::Contact);
});

// 9. Global caps.
it('case 9: creates no row and sends nothing for the 121st new user in a minute', function () {
    Bus::fake();

    foreach (range(1, BotLimits::NEW_USERS_PER_MINUTE + 1) as $i) {
        abzText('/start', 6_100_000 + $i);
    }

    expect(TelegramUser::query()->count())->toBe(BotLimits::NEW_USERS_PER_MINUTE)
        ->and(TelegramUser::query()->where('telegram_id', 6_100_000 + BotLimits::NEW_USERS_PER_MINUTE + 1)->exists())->toBeFalse()
        ->and(Bus::dispatched(SendBotPrompt::class))->toHaveCount(BotLimits::NEW_USERS_PER_MINUTE);
});

it('case 9: creates no customer for the 31st bot registration in an hour and alerts management once, after commit', function () {
    $levels = [];
    $baseline = DB::transactionLevel();
    abzHttp(['api.telegram.org/*' => function ($request) use (&$levels) {
        if (($request->data()['chat_id'] ?? null) === TG_MANAGEMENT_CHAT) {
            $levels[] = DB::transactionLevel();
        }

        return Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
    }]);

    foreach (range(1, BotLimits::CUSTOMERS_PER_HOUR + 2) as $i) {
        abzRegister('+99890'.str_pad((string) (1000000 + $i), 7, '0', STR_PAD_LEFT), userId: 6_200_000 + $i);
    }

    $capped = TelegramUser::query()->where('telegram_id', '>=', 6_200_000)->whereNull('customer_id')->get();

    expect(Customer::query()->count())->toBe(BotLimits::CUSTOMERS_PER_HOUR)
        ->and($capped)->toHaveCount(2)
        ->and($capped->every(fn (TelegramUser $user) => $user->state === TelegramUserState::Registered && $user->contact_id === null))->toBeTrue()
        ->and(abzSent((int) TG_MANAGEMENT_CHAT))->toHaveCount(1)
        ->and($levels)->toBe([$baseline]);
});

// 10. Concurrency (M2): repeated calls stand in for racing workers.
it('case 10: lets the hit counters decide, so interleaved callers never pass the maximum', function () {
    $customers = 0;
    $users = 0;

    foreach (range(1, BotLimits::NEW_USERS_PER_MINUTE + 40) as $i) {
        // Two "workers" interleave on the same shared counters.
        $customers += (int) BotLimits::allowNewCustomer();
        $users += (int) BotLimits::allowNewUser();
        $customers += (int) BotLimits::allowNewCustomer();
    }

    expect($customers)->toBe(BotLimits::CUSTOMERS_PER_HOUR)
        ->and($users)->toBe(BotLimits::NEW_USERS_PER_MINUTE);
});

it('case 10: creates at most the hourly maximum of customers when registrations pile up', function () {
    Bus::fake();

    foreach (range(1, BotLimits::CUSTOMERS_PER_HOUR + 15) as $i) {
        abzRegister('+99893'.str_pad((string) (2000000 + $i), 7, '0', STR_PAD_LEFT), userId: 6_300_000 + $i);
    }

    expect(Customer::query()->count())->toBe(BotLimits::CUSTOMERS_PER_HOUR)
        ->and(TelegramUser::query()->where('state', TelegramUserState::Registered)->count())->toBe(BotLimits::CUSTOMERS_PER_HOUR + 15);
});

it('case 10: sends the per-user notice once however many over-limit updates arrive', function () {
    $results = collect(range(1, BotLimits::PER_MINUTE + 30))->map(fn () => BotLimits::hitUser(ABZ_USER));

    expect($results->filter(fn ($result) => $result === null))->toHaveCount(BotLimits::PER_MINUTE)
        ->and($results->filter(fn ($result) => $result === true))->toHaveCount(1)
        ->and($results->filter(fn ($result) => $result === false))->toHaveCount(29);
});

// 11. Tampered or replayed callback data.
it('case 11: only answers tampered, out-of-state or replayed callbacks', function (TelegramUserState $state, string $data) {
    TelegramUser::factory()->create(['telegram_id' => ABZ_USER, 'locale' => 'en', 'state' => $state, 'name' => 'Ali', 'phone' => ABZ_PHONE, 'registered_at' => $state === TelegramUserState::Registered ? now() : null]);
    $before = abzUser()->only(['state', 'locale', 'name', 'phone', 'declared_company', 'declared_inn', 'registered_at']);

    abzCallback($data);

    expect(collect(telegramApiCalls())->pluck('method')->all())->toBe(['answerCallbackQuery'])
        ->and(abzUser()->only(array_keys($before)))->toEqual($before);
})->with([
    'lang with trailing newline' => [TelegramUserState::Contact, "lang:en\n"],
    'unsupported language' => [TelegramUserState::Contact, 'lang:de'],
    'skip inn while asking company' => [TelegramUserState::Company, 'skip:inn'],
    'replayed name:yes after registration' => [TelegramUserState::Registered, 'name:yes'],
]);

// 12. Free text is stored cleaned and always sent as plain text.
it('case 12: strips bidi, zero-width and line breaks from name and company and never uses parse_mode', function () {
    $user = abzRegister('+998901234567', "\u{202E}Mar\u{200B}ket\nMChJ <b>x</b>\u{202C}", '301234567');

    // Typed name path: replace the suggested name with a hostile one.
    TelegramUser::factory()->create(['telegram_id' => ABZ_USER + 1, 'locale' => 'en', 'state' => TelegramUserState::Name, 'name' => null, 'phone' => '+998935550000']);
    abzText("\u{202E}Ali\u{200B}\r\nVali <b>bold</b>\u{202C}", ABZ_USER + 1);
    $typed = abzUser(ABZ_USER + 1);

    expect($user->declared_company)->toBe('Market MChJ <b>x</b>')
        ->and($user->customer->name)->toBe('Market MChJ <b>x</b>')
        ->and($typed->name)->toBe('Ali Vali <b>bold</b>')
        ->and(preg_match('/[\p{Cc}\p{Cf}]/u', $user->declared_company.$typed->name))->toBe(0);

    abzCallback('skip:company', ABZ_USER + 1);

    $sent = abzSent();

    expect($sent)->not->toBeEmpty();

    foreach ($sent as $data) {
        expect($data)->not->toHaveKey('parse_mode');
    }

    expect(collect($sent)->pluck('text')->implode("\n"))->toContain('Ali Vali <b>bold</b>');
});

// 13. Banned users and the kill switch.
it('case 13: creates no rows and queues no replies for a banned user', function () {
    Bus::fake();
    TelegramUser::factory()->banned()->create(['telegram_id' => ABZ_USER, 'state' => TelegramUserState::Contact, 'locale' => 'en']);

    abzText('/start');
    abzContact(ABZ_PHONE);
    abzText('hello');
    abzCallback('lang:ru');

    $user = abzUser();

    expect($user->phone)->toBeNull()
        ->and($user->locale)->toBe('en')
        ->and($user->state)->toBe(TelegramUserState::Contact)
        ->and(TelegramUser::query()->count())->toBe(1)
        ->and(TelegramMessage::query()->count())->toBe(0)
        ->and(Customer::query()->count())->toBe(0)
        ->and(Contact::query()->count())->toBe(0);

    Bus::assertNotDispatched(SendBotPrompt::class);
    Bus::assertNotDispatched(DeliverBotMessage::class);
    Bus::assertDispatchedTimes(AnswerBotCallback::class, 1);
});

it('case 13: sends nothing from queued jobs while the kill switch is off', function () {
    $user = TelegramUser::factory()->registered()->create(['telegram_id' => ABZ_USER, 'locale' => 'en']);
    $message = TelegramMessage::query()->create(['telegram_user_id' => $user->id, 'direction' => TelegramDirection::Out, 'kind' => TelegramMessageKind::Text, 'text' => 'hi', 'status' => TelegramMessageStatus::Pending]);
    abzBotOn(false);
    Http::fake();

    dispatch(new SendBotPrompt($user->id, ['help'], callbackQueryId: 'cbq-1', clearMarkupMessageId: 5));
    dispatch(new DeliverBotMessage($message->id));
    dispatch(new AnswerBotCallback('cbq-2'));

    Http::assertNothingSent();
    expect($message->fresh()->status)->toBe(TelegramMessageStatus::Pending);
});

// 14. Nothing sensitive in payloads or logs; 403 blocks.
it('case 14: puts no token, phone, chat id or text into any queued job payload', function () {
    Bus::fake();

    abzRegister('+998901234567', 'Secret Company', '301234567');

    $jobs = Bus::dispatched(SendBotPrompt::class)->merge(Bus::dispatched(AnswerBotCallback::class));
    $payload = $jobs->map(fn ($job) => serialize($job))->implode("\n");

    expect($jobs)->not->toBeEmpty()
        ->and($payload)->not->toContain('AAFakeToken')->not->toContain(TG_BOT_TOKEN)
        ->not->toContain('998901234567')->not->toContain((string) ABZ_USER)
        ->not->toContain('Valiyev')->not->toContain('Secret Company')->not->toContain('301234567');
});

it('case 14: sets blocked_at on 403 and logs no token, phone, chat id or text', function (int $status) {
    $logs = abzCaptureLogs();
    abzHttp(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => $status, 'description' => 'Forbidden: bot token '.TG_BOT_TOKEN], $status)]);

    try {
        abzRegister('+998901234567', 'Secret Company', '301234567');
    } catch (TelegramException) {
        // 5xx style failures are rethrown for the queue.
    }

    $user = abzUser();
    $user->forceFill(['blocked_at' => null])->save();
    $message = TelegramMessage::query()->create(['telegram_user_id' => $user->id, 'direction' => TelegramDirection::Out, 'kind' => TelegramMessageKind::Text, 'text' => 'private printer text', 'status' => TelegramMessageStatus::Pending]);
    (new DeliverBotMessage($message->id))->withFakeQueueInteractions()->handle(app(TelegramClient::class));

    $all = implode("\n", $logs());

    expect($logs())->not->toBeEmpty()
        ->and($user->fresh()->blocked_at)->not->toBeNull()
        ->and($message->fresh()->status)->toBe(TelegramMessageStatus::Failed)
        ->and($all)->not->toContain('AAFakeToken')->not->toContain('api.telegram.org')
        ->not->toContain('998901234567')->not->toContain((string) ABZ_USER)
        ->not->toContain('printer')->not->toContain('Secret Company')->not->toContain('Valiyev');
})->with([403]);

// 15. M1: one delivery per outbound message.
it('case 15: delivers a message once when the job runs twice or is retried after a timeout', function () {
    $user = TelegramUser::factory()->registered()->create(['telegram_id' => ABZ_USER]);
    $message = TelegramMessage::query()->create(['telegram_user_id' => $user->id, 'direction' => TelegramDirection::Out, 'kind' => TelegramMessageKind::Text, 'text' => 'once only', 'status' => TelegramMessageStatus::Pending]);
    $client = app(TelegramClient::class);

    abzHttp(['api.telegram.org/*' => Http::sequence()
        ->pushFailedConnection()
        ->push(['ok' => true, 'result' => ['message_id' => 31]])
        ->push(['ok' => true, 'result' => ['message_id' => 32]])]);

    expect(fn () => (new DeliverBotMessage($message->id))->handle($client))->toThrow(TelegramException::class);

    (new DeliverBotMessage($message->id))->handle($client);
    (new DeliverBotMessage($message->id))->handle($client);
    dispatch(new DeliverBotMessage($message->id));

    // One call timed out (never delivered), exactly one reached the user.
    expect(collect(telegramApiCalls())->where('method', 'sendMessage'))->toHaveCount(2)
        ->and($message->fresh()->status)->toBe(TelegramMessageStatus::Sent)
        ->and($message->fresh()->telegram_message_id)->toBe(31);
});

// 16. Conversation manager.
it('case 16: never assigns an inactive manager or one without leads.sales', function (string $kind) {
    $manager = match ($kind) {
        'inactive sales manager' => tap(staffUser(Role::SalesManager), fn (User $user) => $user->forceFill(['is_active' => false])->save()),
        'content manager' => staffUser(Role::ContentManager),
        'service engineer' => staffUser(Role::ServiceEngineer),
    };
    Customer::factory()->create(['phone' => ABZ_PHONE, 'manager_id' => $manager->id]);

    $user = abzRegister(ABZ_PHONE);

    expect($user->customer_id)->not->toBeNull()
        ->and($user->manager_id)->toBeNull();
})->with(['inactive sales manager', 'content manager', 'service engineer']);

// 17. TG-33: source is set in code only.
it('case 17: ignores a tampered source sent through CustomerForm and keeps source=telegram on a bot customer', function () {
    $user = abzRegister(ABZ_PHONE);
    $customer = $user->customer;

    expect($customer->source)->toBe(LeadSource::Telegram);

    $component = Livewire::actingAs(staffUser())->test(CustomerFormScreen::class, ['customer' => $customer]);

    foreach (['form.source' => 'web', 'source' => 'web'] as $property => $value) {
        try {
            $component->set($property, $value);
        } catch (Throwable) {
            // Livewire may refuse an unknown property outright: also fine.
        }
    }

    $component->set('form.name', 'Renamed By Staff')->call('save')->assertHasNoErrors();

    expect($customer->fresh()->name)->toBe('Renamed By Staff')
        ->and($customer->fresh()->source)->toBe(LeadSource::Telegram);

    // A new customer created from the form never gets a source from input.
    $created = Livewire::actingAs(staffUser())->test(CustomerFormScreen::class);

    try {
        $created->set('form.source', 'telegram');
    } catch (Throwable) {
    }

    $created->set('form.type', 'company')->set('form.name', 'Form Made LLC')->set('form.contacts.0.name', 'Bekzod')->call('save')->assertHasNoErrors();

    expect(Customer::query()->where('name', 'Form Made LLC')->sole()->source)->toBeNull();
});

it('case 17: SaveCustomer has no source input and keeps the stored source on update', function () {
    $user = abzRegister(ABZ_PHONE);
    $customer = $user->customer;

    expect(fn () => new CustomerData(...['type' => CustomerType::Company, 'name' => 'X', 'source' => 'web']))->toThrow(Error::class);

    app(SaveCustomer::class)->handle($customer, new CustomerData(type: CustomerType::Company, name: 'Edited Co'), staffUser());

    expect($customer->fresh()->name)->toBe('Edited Co')
        ->and($customer->fresh()->source)->toBe(LeadSource::Telegram);
});
