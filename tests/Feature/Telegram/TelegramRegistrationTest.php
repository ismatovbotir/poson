<?php

use App\Actions\Telegram\HandleTelegramUpdate;
use App\Enums\CustomerType;
use App\Enums\LeadSource;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Enums\TelegramUserState;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Models\User;
use App\Support\Telegram\Bot\BotKeyboards;
use App\Support\Telegram\Bot\BotLimits;
use App\Support\Telegram\Bot\BotSwitch;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Plan 14 v2 B3: registration state machine, CRM matching, identical
 * replies and abuse limits. Bot API faked (fake token), queue sync: every
 * reply is a SendBotPrompt that runs after the update's transaction.
 */
const REG_USER = 5550001;

function regBotOn(bool $on = true): void
{
    Setting::query()->updateOrCreate(['key' => BotSwitch::KEY], ['group' => SettingsArea::TelegramBot->group(), 'value' => $on]);
}

function regNextUpdateId(): int
{
    static $id = 1000;

    return ++$id;
}

/**
 * @param  array<string, mixed>  $message
 */
function regMessage(array $message, int $userId = REG_USER, array $from = []): string
{
    $update = telegramPrivateUpdate(regNextUpdateId(), $message, $userId);
    $update['message']['from'] = array_merge($update['message']['from'], ['username' => 'ali_user', 'last_name' => 'Valiyev'], $from);

    if (! array_key_exists('text', $message)) {
        unset($update['message']['text']);
    }

    return app(HandleTelegramUpdate::class)->handle($update);
}

function regText(string $text, int $userId = REG_USER): string
{
    return regMessage(['text' => $text], $userId);
}

function regCallback(string $data, int $userId = REG_USER): string
{
    return app(HandleTelegramUpdate::class)->handle([
        'update_id' => regNextUpdateId(),
        'callback_query' => [
            'id' => 'cbq-'.regNextUpdateId(),
            'from' => ['id' => $userId, 'is_bot' => false, 'first_name' => 'Ali'],
            'message' => ['message_id' => 77, 'chat' => ['id' => $userId, 'type' => 'private']],
            'data' => $data,
        ],
    ]);
}

/**
 * @param  array<string, mixed>  $extra  Merged into the message (forward_origin …)
 */
function regContact(?string $phone, ?int $contactUserId = REG_USER, int $userId = REG_USER, array $extra = []): string
{
    $contact = array_filter(['phone_number' => $phone, 'first_name' => 'Ali', 'user_id' => $contactUserId, 'vcard' => "BEGIN:VCARD\nTEL:+998900000000\nEND:VCARD"], fn ($value) => $value !== null);

    return regMessage(array_merge(['contact' => $contact], $extra), $userId);
}

/**
 * Drive a fresh user through the whole flow.
 */
function regRegister(string $phone, ?string $company = null, ?string $inn = null, int $userId = REG_USER, string $locale = 'en'): TelegramUser
{
    regText('/start', $userId);
    regCallback("lang:{$locale}", $userId);
    regContact($phone, $userId, $userId);
    regCallback('name:yes', $userId);

    if ($company === null) {
        regCallback('skip:company', $userId);
    } else {
        regText($company, $userId);
        $inn === null ? regCallback('skip:inn', $userId) : regText($inn, $userId);
    }

    return TelegramUser::query()->where('telegram_id', $userId)->sole();
}

/**
 * sendMessage calls (optionally to one chat).
 *
 * @return list<array<string, mixed>>
 */
function regSent(?int $chatId = null): array
{
    return collect(telegramApiCalls())
        ->where('method', 'sendMessage')
        ->filter(fn (array $call) => $chatId === null || $call['data']['chat_id'] === (string) $chatId)
        ->pluck('data')->values()->all();
}

function regLastText(): ?string
{
    return collect(regSent())->last()['text'] ?? null;
}

function regUser(int $userId = REG_USER): TelegramUser
{
    return TelegramUser::query()->where('telegram_id', $userId)->sole();
}

beforeEach(function () {
    telegramBotConfigured();
    regBotOn();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 900]])]);
});

// --- State machine -------------------------------------------------------

it('greets a new user with the three-language picker as plain text', function () {
    expect(regText('/start'))->toBe(HandleTelegramUpdate::PROCESSED);

    $sent = regSent(REG_USER);

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['text'])->toBe(__('telegram_bot.language.prompt'))
        ->and($sent[0])->not->toHaveKey('parse_mode')
        ->and(collect($sent[0]['reply_markup']['inline_keyboard'])->flatten(1)->pluck('callback_data')->all())->toBe(['lang:uz', 'lang:ru', 'lang:en'])
        ->and(regUser()->state)->toBe(TelegramUserState::Language)
        ->and(regUser()->locale)->toBeNull();
});

it('saves the language, asks for consent with the privacy link and the share-contact button', function () {
    regText('/start');
    regCallback('lang:ru');

    $sent = collect(regSent())->last();
    $methods = collect(telegramApiCalls())->pluck('method');

    expect(regUser()->locale)->toBe('ru')
        ->and(regUser()->state)->toBe(TelegramUserState::Contact)
        ->and($sent['text'])->toBe(__('telegram_bot.consent.text', ['privacy_url' => route('site.page', ['locale' => 'ru', 'page' => 'privacy'])], 'ru'))
        ->and($sent['reply_markup']['keyboard'][0][0])->toMatchArray(['request_contact' => true, 'text' => __('telegram_bot.consent.share_button', [], 'ru')])
        ->and($methods)->toContain('answerCallbackQuery')->toContain('editMessageReplyMarkup');
});

it('registers through every step and ends with the welcome and the main menu', function () {
    $user = regRegister('+998 90 123 45 67', 'Market MChJ', '301234567');

    $last = collect(regSent())->last();

    expect($user->state)->toBe(TelegramUserState::Registered)
        ->and($user->registered_at)->not->toBeNull()
        ->and($user->consent_at)->not->toBeNull()
        ->and($user->phone)->toBe('+998901234567')
        ->and($user->name)->toBe('Ali Valiyev')
        ->and($user->declared_company)->toBe('Market MChJ')
        ->and($user->declared_inn)->toBe('301234567')
        ->and($last['text'])->toBe(__('telegram_bot.welcome', ['name' => 'Ali Valiyev'], 'en'))
        ->and($last['reply_markup'])->toBe(BotKeyboards::markup(BotKeyboards::MENU, 'en'))
        ->and(TelegramMessage::query()->count())->toBe(0);
});

it('re-sends the current step on /start mid-registration without changing state', function (TelegramUserState $state, ?string $name, string $expectedKey, ?string $keyboard) {
    $user = TelegramUser::factory()->create(['telegram_id' => REG_USER, 'locale' => 'en', 'state' => $state, 'name' => $name, 'phone' => $state === TelegramUserState::Contact ? null : '+998901234567', 'registered_at' => $state === TelegramUserState::Registered ? now() : null]);

    regText('/start');

    $sent = regSent(REG_USER);
    $params = ['name' => (string) $name, 'privacy_url' => route('site.page', ['locale' => 'en', 'page' => 'privacy'])];

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['text'])->toBe(__("telegram_bot.{$expectedKey}", $params, 'en'))
        ->and($sent[0]['reply_markup'] ?? null)->toBe(BotKeyboards::markup($keyboard, 'en'))
        ->and($user->fresh()->state)->toBe($state);
})->with([
    'language' => [TelegramUserState::Language, null, 'language.prompt', BotKeyboards::LANGUAGE],
    'contact' => [TelegramUserState::Contact, null, 'consent.text', BotKeyboards::CONTACT],
    'name to confirm' => [TelegramUserState::Name, 'Ali Valiyev', 'name.confirm', BotKeyboards::NAME_CONFIRM],
    'name to type' => [TelegramUserState::Name, null, 'name.prompt', BotKeyboards::REMOVE],
    'company' => [TelegramUserState::Company, 'Ali', 'company.prompt', BotKeyboards::SKIP_COMPANY],
    'inn' => [TelegramUserState::Inn, 'Ali', 'inn.prompt', BotKeyboards::SKIP_INN],
    'registered → main menu' => [TelegramUserState::Registered, 'Ali', 'menu.main', BotKeyboards::MENU],
]);

it('accepts /start with a deep-link payload or the bot mention', function (string $command) {
    regText($command);

    expect(regLastText())->toBe(__('telegram_bot.language.prompt'));
})->with(['/start ref123', '/start@PosonBot', '/START']);

it('shows the language picker on /language in any state and keeps the step after a change', function () {
    TelegramUser::factory()->create(['telegram_id' => REG_USER, 'locale' => 'uz', 'state' => TelegramUserState::Name, 'name' => null, 'phone' => '+998901234567']);

    regText('/language');
    expect(regLastText())->toBe(__('telegram_bot.language.prompt'))
        ->and(regUser()->state)->toBe(TelegramUserState::Name);

    regCallback('lang:en');

    expect(regUser()->locale)->toBe('en')
        ->and(regUser()->state)->toBe(TelegramUserState::Name)
        ->and(regLastText())->toBe(__('telegram_bot.language.changed', [], 'en')."\n\n".__('telegram_bot.name.prompt', [], 'en'));
});

it('changes the language of a registered user from the menu button and redraws the menu', function () {
    TelegramUser::factory()->registered()->create(['telegram_id' => REG_USER, 'locale' => 'uz']);

    regText(__('telegram_bot.menu.language', [], 'uz'));
    expect(regLastText())->toBe(__('telegram_bot.language.prompt'));

    regCallback('lang:ru');
    $last = collect(regSent())->last();

    expect(regUser()->locale)->toBe('ru')
        ->and($last['text'])->toBe(__('telegram_bot.language.changed', [], 'ru'))
        ->and($last['reply_markup'])->toBe(BotKeyboards::markup(BotKeyboards::MENU, 'ru'));
});

it('answers /help with the help text plus the current step or the menu', function (bool $registered) {
    $factory = TelegramUser::factory()->state(['telegram_id' => REG_USER, 'locale' => 'en']);
    $registered ? $factory->registered()->create() : $factory->create(['state' => TelegramUserState::Company, 'name' => 'Ali', 'phone' => '+998901234567']);

    regText('/help');

    $expected = __('telegram_bot.help', [], 'en')."\n\n".__($registered ? 'telegram_bot.menu.main' : 'telegram_bot.company.prompt', [], 'en');

    expect(regLastText())->toBe($expected);
})->with(['registered' => true, 'mid-registration' => false]);

it('answers /help before a language is chosen with the help text and the picker', function () {
    regText('/help');

    $last = collect(regSent())->last();

    expect($last['text'])->toBe(__('telegram_bot.help', [], 'uz')."\n\n".__('telegram_bot.language.prompt'))
        ->and($last['reply_markup'])->toBe(BotKeyboards::markup(BotKeyboards::LANGUAGE, 'uz'))
        ->and(regUser()->state)->toBe(TelegramUserState::Language);
});

it('only answers tampered or out-of-state callbacks, without any state change or message', function (TelegramUserState $state, string $data) {
    TelegramUser::factory()->create(['telegram_id' => REG_USER, 'locale' => 'en', 'state' => $state, 'name' => 'Ali', 'phone' => '+998901234567', 'registered_at' => $state === TelegramUserState::Registered ? now() : null]);
    $before = regUser()->only(['state', 'locale', 'name', 'declared_company', 'declared_inn', 'registered_at']);

    regCallback($data);

    expect(collect(telegramApiCalls())->pluck('method')->all())->toBe(['answerCallbackQuery'])
        ->and(regUser()->only(array_keys($before)))->toEqual($before);
})->with([
    'unknown language' => [TelegramUserState::Contact, 'lang:de'],
    'language with suffix' => [TelegramUserState::Contact, 'lang:en;drop table'],
    'language with inner space' => [TelegramUserState::Contact, 'lang: en'],
    'language with trailing newline (raw data)' => [TelegramUserState::Contact, "lang:en\n"],
    'language with leading space (raw data)' => [TelegramUserState::Contact, ' lang:en'],
    'language with NUL (raw data)' => [TelegramUserState::Contact, "lang:en\0"],
    'language upper case' => [TelegramUserState::Contact, 'LANG:EN'],
    'unknown skip' => [TelegramUserState::Company, 'skip:all'],
    'unknown action' => [TelegramUserState::Name, 'admin:1'],
    'empty' => [TelegramUserState::Name, ''],
    'long garbage' => [TelegramUserState::Name, str_repeat('x', 64)],
    'skip inn while asking company' => [TelegramUserState::Company, 'skip:inn'],
    'skip company while asking inn' => [TelegramUserState::Inn, 'skip:company'],
    'name yes while asking contact' => [TelegramUserState::Contact, 'name:yes'],
    'skip company when registered' => [TelegramUserState::Registered, 'skip:company'],
    'name change when registered' => [TelegramUserState::Registered, 'name:change'],
]);

it('lets the user change the suggested name and validates the typed one', function () {
    TelegramUser::factory()->create(['telegram_id' => REG_USER, 'locale' => 'en', 'state' => TelegramUserState::Name, 'name' => 'Ali Valiyev', 'phone' => '+998901234567']);

    regCallback('name:change');
    expect(regUser()->name)->toBeNull()
        ->and(regLastText())->toBe(__('telegram_bot.name.prompt', [], 'en'));

    regText('Botir');
    expect(regUser()->name)->toBe('Botir')
        ->and(regUser()->state)->toBe(TelegramUserState::Company);
});

// --- Contact (condition 5, TG-29) ------------------------------------------

it('refuses a contact that is not the sender\'s own and stores nothing', function (Closure $send, string $expectedKey) {
    TelegramUser::factory()->unregistered()->create(['telegram_id' => REG_USER, 'locale' => 'en']);

    $send();

    $user = regUser();

    expect($user->state)->toBe(TelegramUserState::Contact)
        ->and($user->phone)->toBeNull()
        ->and($user->consent_at)->toBeNull()
        ->and(regLastText())->toBe(__("telegram_bot.{$expectedKey}", [], 'en'))
        ->and(collect(regSent())->last()['reply_markup'])->toBe(BotKeyboards::markup(BotKeyboards::CONTACT, 'en'));
})->with([
    'someone else\'s contact' => [fn () => regContact('+998901234567', 9990001), 'contact.not_own'],
    'contact without user id' => [fn () => regContact('+998901234567', null), 'contact.not_own'],
    'forwarded own contact' => [fn () => regContact('+998901234567', REG_USER, extra: ['forward_origin' => ['type' => 'user', 'date' => 1]]), 'contact.not_own'],
    'vcard only, no phone_number' => [fn () => regContact(null), 'contact.invalid'],
    'unreadable number' => [fn () => regContact('12'), 'contact.invalid'],
    'typed phone instead of the button' => [fn () => regText('+998901234567'), 'contact.use_button'],
    'photo' => [fn () => regMessage(['photo' => [['file_id' => 'x', 'file_unique_id' => 'y', 'width' => 1, 'height' => 1]]]), 'contact.use_button'],
]);

it('normalizes the shared number from its digits and sets consent', function (string $shared, string $expected) {
    TelegramUser::factory()->unregistered()->create(['telegram_id' => REG_USER, 'locale' => 'en', 'first_name' => 'Ali', 'last_name' => null]);

    regContact($shared);

    expect(regUser()->phone)->toBe($expected)
        ->and(regUser()->consent_at)->not->toBeNull()
        ->and(regUser()->state)->toBe(TelegramUserState::Name);
})->with([
    'uz without plus' => ['998901234567', '+998901234567'],
    'uz with plus' => ['+998901234567', '+998901234567'],
    'russia' => ['79161234567', '+79161234567'],
    'telegram anonymous' => ['88801234567', '+88801234567'],
]);

it('ignores a contact sent after the contact step', function () {
    TelegramUser::factory()->create(['telegram_id' => REG_USER, 'locale' => 'en', 'state' => TelegramUserState::Company, 'name' => 'Ali', 'phone' => '+998901234567']);

    regContact('+998935556677');

    expect(regUser()->phone)->toBe('+998901234567')
        ->and(regUser()->state)->toBe(TelegramUserState::Company)
        ->and(regLastText())->toBe(__('telegram_bot.registration.continue', [], 'en')."\n\n".__('telegram_bot.company.prompt', [], 'en'));
});

it('never links or copies a Telegram anonymous +888 number into the CRM (TG-29, H1)', function () {
    $existing = Customer::factory()->create(['phone' => '+88801234567']);
    Contact::factory()->for($existing)->create(['phone' => '+88801234567']);

    $user = regRegister('88801234567');
    $second = regRegister('88801234567', userId: 5550002);

    expect($user->phone)->toBe('+88801234567')
        ->and($user->customer_id)->not->toBe($existing->id)
        ->and($user->customer->source)->toBe(LeadSource::Telegram)
        ->and($user->customer->phone)->toBeNull()
        ->and($user->contact->phone)->toBeNull()
        ->and($user->link_verified_at)->toBeNull()
        ->and($second->customer_id)->not->toBe($user->customer_id)
        ->and($second->customer_id)->not->toBe($existing->id)
        ->and($second->account_changed)->toBeFalse()
        ->and(Customer::query()->count())->toBe(3);
});

// --- Name / company / INN ------------------------------------------------

it('validates and cleans the typed name', function (string $typed, ?string $stored) {
    TelegramUser::factory()->create(['telegram_id' => REG_USER, 'locale' => 'en', 'state' => TelegramUserState::Name, 'name' => null, 'phone' => '+998901234567']);

    regText($typed);

    if ($stored === null) {
        expect(regUser()->state)->toBe(TelegramUserState::Name)
            ->and(regUser()->name)->toBeNull()
            ->and(regLastText())->toBe(__('telegram_bot.name.invalid', [], 'en'));
    } else {
        expect(regUser()->name)->toBe($stored)
            ->and(regUser()->state)->toBe(TelegramUserState::Company);
    }
})->with([
    'too short' => ['A', null],
    'too long' => [str_repeat('a', 121), null],
    'exactly 120' => [str_repeat('a', 120), str_repeat('a', 120)],
    'bidi override stripped' => ["\u{202E}Ali\u{202C}", 'Ali'],
    'zero-width only' => ["\u{200B}\u{200B}\u{2066}", null],
    'control chars and newline' => ["Ali\x07\nVali", 'Ali Vali'],
    'right-to-left isolate inside' => ["Al\u{2067}i", 'Ali'],
    'cyrillic' => ['Алишер', 'Алишер'],
]);

it('does not suggest a profile name that is too short and asks for one instead', function () {
    TelegramUser::factory()->unregistered()->create(['telegram_id' => REG_USER, 'locale' => 'en']);

    regMessage(['contact' => ['phone_number' => '998901234567', 'user_id' => REG_USER, 'first_name' => 'A']], from: ['first_name' => "\u{202E}A", 'last_name' => null]);

    expect(regUser()->name)->toBeNull()
        ->and(regLastText())->toBe(__('telegram_bot.name.prompt', [], 'en'))
        ->and(collect(regSent())->last()['reply_markup'])->toBe(['remove_keyboard' => true]);
});

it('validates and cleans the declared company', function (string $typed, ?string $stored) {
    TelegramUser::factory()->create(['telegram_id' => REG_USER, 'locale' => 'en', 'state' => TelegramUserState::Company, 'name' => 'Ali', 'phone' => '+998901234567']);

    regText($typed);

    if ($stored === null) {
        expect(regUser()->state)->toBe(TelegramUserState::Company)
            ->and(regUser()->declared_company)->toBeNull()
            ->and(regLastText())->toBe(__('telegram_bot.company.invalid', [], 'en'));
    } else {
        expect(regUser()->declared_company)->toBe($stored)
            ->and(regUser()->state)->toBe(TelegramUserState::Inn);
    }
})->with([
    'too long' => [str_repeat('b', 161), null],
    'exactly 160' => [str_repeat('b', 160), str_repeat('b', 160)],
    'only format chars' => ["\u{202E}\u{200F}", null],
    'bidi stripped' => ["\u{202E}Market\u{202C} MChJ", 'Market MChJ'],
]);

it('accepts only a 9-digit INN', function (string $typed, ?string $stored) {
    TelegramUser::factory()->create(['telegram_id' => REG_USER, 'locale' => 'en', 'state' => TelegramUserState::Inn, 'name' => 'Ali', 'phone' => '+998901234567', 'declared_company' => 'Market']);

    regText($typed);

    if ($stored === null) {
        expect(regUser()->state)->toBe(TelegramUserState::Inn)
            ->and(regUser()->declared_inn)->toBeNull()
            ->and(regLastText())->toBe(__('telegram_bot.inn.invalid', [], 'en'));
    } else {
        expect(regUser()->declared_inn)->toBe($stored)
            ->and(regUser()->state)->toBe(TelegramUserState::Registered);
    }
})->with([
    '8 digits' => ['12345678', null],
    '10 digits' => ['1234567890', null],
    'letters' => ['12345678a', null],
    'unicode digits' => ['١٢٣٤٥٦٧٨٩', null],
    'spaces allowed' => ['301 234 567', '301234567'],
    'plain' => ['301234567', '301234567'],
]);

it('skips the INN step when the company is skipped', function () {
    $user = regRegister('+998901234567');

    expect($user->declared_company)->toBeNull()
        ->and($user->declared_inn)->toBeNull()
        ->and($user->state)->toBe(TelegramUserState::Registered)
        ->and(collect(regSent())->pluck('text')->contains(__('telegram_bot.inn.prompt', [], 'en')))->toBeFalse();
});

it('re-prompts free text and media before registration without storing them', function () {
    TelegramUser::factory()->create(['telegram_id' => REG_USER, 'locale' => 'en', 'state' => TelegramUserState::Name, 'name' => 'Ali', 'phone' => '+998901234567']);

    regMessage(['photo' => [['file_id' => 'x', 'file_unique_id' => 'y', 'width' => 1, 'height' => 1]], 'caption' => 'my invoice']);
    regText('/unknown');

    expect(regUser()->state)->toBe(TelegramUserState::Name)
        ->and(regUser()->name)->toBe('Ali')
        ->and(collect(regSent())->pluck('text')->all())->each->toBe(__('telegram_bot.registration.continue', [], 'en')."\n\n".__('telegram_bot.name.confirm', ['name' => 'Ali'], 'en'))
        ->and(TelegramMessage::query()->count())->toBe(0);
});

it('handles main menu labels as commands in any bot language and stores nothing', function (string $label, string $expected) {
    TelegramUser::factory()->registered()->create(['telegram_id' => REG_USER, 'locale' => 'en']);

    regText($label);

    expect(regLastText())->toBe($expected)
        ->and(TelegramMessage::query()->count())->toBe(0);
})->with([
    'catalog (en)' => [fn () => __('telegram_bot.menu.catalog', [], 'en'), fn () => __('telegram_bot.menu.catalog_reply', ['url' => route('site.catalog.index', ['locale' => 'en'])], 'en')],
    'catalog (old uz keyboard)' => [fn () => __('telegram_bot.menu.catalog', [], 'uz'), fn () => __('telegram_bot.menu.catalog_reply', ['url' => route('site.catalog.index', ['locale' => 'en'])], 'en')],
    'contact a manager (ru)' => [fn () => __('telegram_bot.menu.contact_manager', [], 'ru'), fn () => __('telegram_bot.menu.contact_manager_reply', [], 'en')],
]);

it('leaves registered users\' free text to the inbox hook (B4): nothing stored or sent yet', function () {
    TelegramUser::factory()->registered()->create(['telegram_id' => REG_USER, 'locale' => 'en']);

    regText('I need a Zebra ZD421');

    expect(regSent())->toBe([])
        ->and(TelegramMessage::query()->count())->toBe(0);
});

it('ignores banned users silently', function () {
    TelegramUser::factory()->banned()->create(['telegram_id' => REG_USER, 'state' => TelegramUserState::Contact]);

    regText('/start');
    regContact('+998901234567');

    expect(telegramApiCalls())->toBe([])
        ->and(regUser()->phone)->toBeNull();
});

it('sends nothing and creates no row while the kill switch is off', function () {
    regBotOn(false);

    expect(regText('/start'))->toBe(HandleTelegramUpdate::DISABLED)
        ->and(regCallback('lang:en'))->toBe(HandleTelegramUpdate::DISABLED);

    expect(telegramApiCalls())->toBe([])
        ->and(TelegramUser::query()->count())->toBe(0);
});

// --- CRM matching (§7, conditions 6 and 7) -----------------------------------

it('links a customer by its own phone and adds a contact for the number', function (bool $hasContacts) {
    $customer = Customer::factory()->create(['phone' => '+998901234567', 'email' => 'boss@market.uz', 'source' => null]);

    if ($hasContacts) {
        Contact::factory()->for($customer)->create(['phone' => '+998935550000', 'is_primary' => true]);
    }

    $before = $customer->fresh()->toArray();

    $user = regRegister('+998901234567', 'Other Name LLC', '309999999');
    $contact = $user->contact;

    expect($user->customer_id)->toBe($customer->id)
        ->and($contact->customer_id)->toBe($customer->id)
        ->and($contact->phone)->toBe('+998901234567')
        ->and($contact->name)->toBe('Ali Valiyev')
        ->and($contact->telegram)->toBe('@ali_user')
        ->and($contact->is_primary)->toBe(! $hasContacts)
        ->and($customer->fresh()->toArray())->toBe($before)
        ->and(Customer::query()->count())->toBe(1);
})->with(['customer without contacts' => false, 'customer with other contacts' => true]);

it('links the customer\'s contact that has the same phone instead of adding one', function () {
    $customer = Customer::factory()->create(['phone' => '+998901234567']);
    $contact = Contact::factory()->for($customer)->create(['phone' => '+998901234567', 'telegram' => '@old_name']);

    $user = regRegister('998901234567');

    expect($user->contact_id)->toBe($contact->id)
        ->and($contact->fresh()->telegram)->toBe('@old_name')
        ->and(Contact::query()->count())->toBe(1);
});

it('links a contact by phone with its customer and fills contacts.telegram only when empty', function (?string $telegram, string $expected) {
    $customer = Customer::factory()->create(['phone' => '+998711112233']);
    $contact = Contact::factory()->for($customer)->create(['phone' => '+998901234567', 'telegram' => $telegram, 'name' => 'Alisher Karimov', 'email' => 'a@k.uz']);
    $before = $contact->fresh()->only(['name', 'phone', 'email', 'position', 'is_primary', 'customer_id']);
    $customerBefore = $customer->fresh()->toArray();

    $user = regRegister('+998901234567', 'Typed Company', '301234567');

    expect($user->customer_id)->toBe($customer->id)
        ->and($user->contact_id)->toBe($contact->id)
        ->and($contact->fresh()->telegram)->toBe($expected)
        ->and($contact->fresh()->only(array_keys($before)))->toBe($before)
        ->and($customer->fresh()->toArray())->toBe($customerBefore)
        ->and(Customer::query()->count())->toBe(1);
})->with([
    'empty telegram' => [null, '@ali_user'],
    'blank telegram' => ['', '@ali_user'],
    'existing telegram kept' => ['@someone', '@someone'],
]);

it('creates an individual customer with source telegram when nothing matches', function () {
    $user = regRegister('+998901234567');
    $customer = $user->customer;

    expect($customer->type)->toBe(CustomerType::Individual)
        ->and($customer->name)->toBe('Ali Valiyev')
        ->and($customer->phone)->toBe('+998901234567')
        ->and($customer->inn)->toBeNull()
        ->and($customer->source)->toBe(LeadSource::Telegram)
        ->and($customer->manager_id)->toBeNull()
        ->and($customer->locale)->toBe('en')
        ->and($customer->contacts)->toHaveCount(1)
        ->and($customer->contacts->first()->is_primary)->toBeTrue()
        ->and($customer->contacts->first()->id)->toBe($user->contact_id)
        ->and($customer->activities()->sole()->meta)->toBe(['source' => 'telegram'])
        ->and($user->manager_id)->toBeNull();
});

it('creates a company customer with the declared INN only when it is unused', function (bool $innTaken) {
    if ($innTaken) {
        Customer::factory()->create(['inn' => '301234567', 'phone' => '+998711112233']);
    }

    $user = regRegister('+998901234567', 'Market MChJ', '301234567');
    $customer = $user->customer;

    expect($customer->type)->toBe(CustomerType::Company)
        ->and($customer->name)->toBe('Market MChJ')
        ->and($customer->inn)->toBe($innTaken ? null : '301234567')
        ->and($user->declared_inn)->toBe('301234567')
        ->and($customer->contacts->first()->name)->toBe('Ali Valiyev');
})->with(['unused INN' => false, 'INN of another customer' => true]);

it('never links by INN', function () {
    $owner = Customer::factory()->create(['inn' => '301234567', 'phone' => '+998711112233']);

    $user = regRegister('+998901234567', 'Market MChJ', '301234567');

    expect($user->customer_id)->not->toBe($owner->id)
        ->and($owner->fresh()->contacts)->toHaveCount(0);
});

it('creates a new customer for a foreign number and matches foreign numbers exactly', function () {
    $user = regRegister('79161234567');

    expect($user->customer->phone)->toBe('+79161234567');

    $second = regRegister('79161234567', userId: 5550002);

    expect($second->customer_id)->toBe($user->customer_id)
        ->and(Customer::query()->count())->toBe(1);
});

it('assigns the conversation to the customer\'s manager only when active with leads.sales', function (string $kind, bool $assigned) {
    $manager = match ($kind) {
        'sales manager' => staffUser(Role::SalesManager),
        'inactive sales manager' => tap(staffUser(Role::SalesManager), fn (User $user) => $user->forceFill(['is_active' => false])->save()),
        'content manager' => staffUser(Role::ContentManager),
        'none' => null,
    };
    Customer::factory()->create(['phone' => '+998901234567', 'manager_id' => $manager?->id]);

    $user = regRegister('+998901234567');

    expect($user->manager_id)->toBe($assigned ? $manager->id : null);
})->with([
    ['sales manager', true],
    ['inactive sales manager', false],
    ['content manager', false],
    ['none', false],
]);

it('allows a second Telegram account for the same contact and flags account_changed', function () {
    $first = regRegister('+998901234567');
    $second = regRegister('+998901234567', userId: 5550002);

    expect($second->contact_id)->toBe($first->contact_id)
        ->and($second->account_changed)->toBeTrue()
        ->and($first->fresh()->account_changed)->toBeFalse()
        ->and(Customer::query()->count())->toBe(1);
});

it('never takes customers.source from mass assignment (TG-33)', function () {
    expect(fn () => Customer::query()->create(['type' => CustomerType::Individual, 'name' => 'X', 'price_level' => 'retail', 'locale' => 'uz', 'source' => 'telegram']))
        ->toThrow(MassAssignmentException::class);
});

it('sends exactly the same texts whether the phone and INN are known or not (condition 6)', function () {
    $known = Customer::factory()->create(['phone' => '+998901234567', 'inn' => '301234567', 'name' => 'Secret Customer']);
    Contact::factory()->for($known)->create(['phone' => '+998901234567', 'name' => 'Secret Person']);

    regRegister('+998901234567', 'Market MChJ', '301234567', userId: 5550001);
    regRegister('+998935557788', 'Market MChJ', '301234567', userId: 5550002);

    $texts = fn (int $chat) => collect(regSent($chat))->map(fn (array $call) => [$call['text'], $call['reply_markup'] ?? null])->all();

    expect($texts(5550001))->toBe($texts(5550002))
        ->and(regUser(5550001)->customer_id)->toBe($known->id)
        ->and(collect(regSent())->pluck('text')->implode(' '))->not->toContain('Secret');
});

// --- Limits (condition 8) ---------------------------------------------------

it('drops updates over 20 a minute with one rate_limited notice', function () {
    TelegramUser::factory()->unregistered()->create(['telegram_id' => REG_USER, 'locale' => 'en']);

    foreach (range(1, 25) as $i) {
        regText('hello '.$i);
    }

    $texts = collect(regSent())->pluck('text');

    expect($texts->filter(fn ($text) => $text === __('telegram_bot.contact.use_button', [], 'en')))->toHaveCount(BotLimits::PER_MINUTE)
        ->and($texts->filter(fn ($text) => $text === __('telegram_bot.rate_limited', [], 'en')))->toHaveCount(1);

    // A new minute: processed again.
    $this->travel(61)->seconds();
    regText('again');

    expect(regLastText())->toBe(__('telegram_bot.contact.use_button', [], 'en'));
});

it('counts callbacks too and changes nothing over the limit', function () {
    TelegramUser::factory()->create(['telegram_id' => REG_USER, 'locale' => 'en', 'state' => TelegramUserState::Company, 'name' => 'Ali', 'phone' => '+998901234567']);

    foreach (range(1, BotLimits::PER_MINUTE) as $i) {
        RateLimiter::hit(BotLimits::userKey(REG_USER, 'minute'), 60);
    }

    regCallback('skip:company');

    expect(regUser()->state)->toBe(TelegramUserState::Company)
        ->and(regLastText())->toBe(__('telegram_bot.rate_limited', [], 'en'));
});

it('drops updates over 300 a day with one notice', function () {
    TelegramUser::factory()->unregistered()->create(['telegram_id' => REG_USER, 'locale' => 'en']);

    foreach (range(1, BotLimits::PER_DAY) as $i) {
        RateLimiter::hit(BotLimits::userKey(REG_USER, 'day'), 86400);
    }

    regText('one');
    regText('two');
    $this->travel(2)->minutes();
    regText('three');

    expect(collect(regSent())->pluck('text')->all())->toBe([__('telegram_bot.rate_limited', [], 'en')]);
});

it('creates at most 120 new conversations a minute and drops the rest silently', function () {
    TelegramUser::factory()->unregistered()->create(['telegram_id' => REG_USER, 'locale' => 'en']);

    foreach (range(1, BotLimits::NEW_USERS_PER_MINUTE) as $i) {
        RateLimiter::hit(BotLimits::NEW_USERS_KEY, 60);
    }

    regText('/start', 5550009);
    regText('/start');

    expect(TelegramUser::query()->where('telegram_id', 5550009)->exists())->toBeFalse()
        ->and(regSent(5550009))->toBe([])
        ->and(regSent(REG_USER))->toHaveCount(1);
});

it('stops creating customers over 30 an hour, still registers and alerts management once', function () {
    foreach (range(1, BotLimits::CUSTOMERS_PER_HOUR) as $i) {
        RateLimiter::hit(BotLimits::CUSTOMERS_KEY, 3600);
    }

    $first = regRegister('+998901234567', userId: 5550001);
    $second = regRegister('+998935557788', userId: 5550002);

    $alerts = regSent((int) TG_MANAGEMENT_CHAT);

    expect($first->state)->toBe(TelegramUserState::Registered)
        ->and($first->customer_id)->toBeNull()
        ->and($second->customer_id)->toBeNull()
        ->and(Customer::query()->count())->toBe(0)
        ->and(collect(regSent(5550001))->last()['text'])->toBe(__('telegram_bot.welcome', ['name' => 'Ali Valiyev'], 'en'))
        ->and($alerts)->toHaveCount(1)
        ->and($alerts[0]['text'])->toContain(e(__('telegram.monitoring.events.telegram_customer_cap', [], 'uz')));
});

it('still links known phones while the customer cap is reached', function () {
    foreach (range(1, BotLimits::CUSTOMERS_PER_HOUR) as $i) {
        RateLimiter::hit(BotLimits::CUSTOMERS_KEY, 3600);
    }
    $customer = Customer::factory()->create(['phone' => '+998901234567']);

    expect(regRegister('+998901234567')->customer_id)->toBe($customer->id);
});

it('lets at most the cap through under repeated calls (atomic hit)', function () {
    $customers = collect(range(1, BotLimits::CUSTOMERS_PER_HOUR + 15))->filter(fn () => BotLimits::allowNewCustomer());
    $users = collect(range(1, BotLimits::NEW_USERS_PER_MINUTE + 15))->filter(fn () => BotLimits::allowNewUser());

    expect($customers)->toHaveCount(BotLimits::CUSTOMERS_PER_HOUR)
        ->and($users)->toHaveCount(BotLimits::NEW_USERS_PER_MINUTE);
});

it('always stops the button spinner, even when the update is dropped', function (Closure $arrange) {
    $arrange();

    regCallback('lang:en', 5550009);

    expect(collect(telegramApiCalls())->pluck('method')->all())->toBe(['answerCallbackQuery'])
        ->and(TelegramUser::query()->where('telegram_id', 5550009)->value('locale'))->not->toBe('en');
})->with([
    'banned user' => [fn () => TelegramUser::factory()->banned()->create(['telegram_id' => 5550009, 'locale' => 'uz'])],
    'new-user cap reached' => [function () {
        foreach (range(1, BotLimits::NEW_USERS_PER_MINUTE) as $i) {
            RateLimiter::hit(BotLimits::NEW_USERS_KEY, 60);
        }
    }],
    'second tap over the rate limit' => [function () {
        TelegramUser::factory()->create(['telegram_id' => 5550009, 'locale' => 'uz', 'state' => TelegramUserState::Contact]);
        foreach (range(1, BotLimits::PER_MINUTE + 1) as $i) {
            RateLimiter::hit(BotLimits::userKey(5550009, 'minute'), 60);
        }
        Cache::add(BotLimits::userKey(5550009, 'minute').':noticed', true, 60);
    }],
]);

it('answers a callback from an unknown user by starting the conversation normally', function () {
    regCallback('skip:inn', 5550009);

    expect(collect(telegramApiCalls())->pluck('method')->all())->toBe(['answerCallbackQuery'])
        ->and(TelegramUser::query()->where('telegram_id', 5550009)->value('state'))->toBe(TelegramUserState::Language);
});

// --- Brand rule ------------------------------------------------------------

it('keeps the bot copy free of discount and low-price wording in every language', function (string $locale) {
    $texts = collect(require lang_path("{$locale}/telegram_bot.php"))->flatten()->implode(' ');

    expectNoBannedWording($texts);
})->with(['uz', 'ru', 'en']);

it('replies through the real webhook route after the update commits', function () {
    RateLimiter::clear('telegram-webhook');

    postTelegramUpdate(telegramPrivateUpdate(regNextUpdateId(), ['text' => '/start']))->assertOk();

    expect(regLastText())->toBe(__('telegram_bot.language.prompt'))
        ->and(regUser()->state)->toBe(TelegramUserState::Language);
});
