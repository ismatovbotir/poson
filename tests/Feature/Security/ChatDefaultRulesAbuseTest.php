<?php

use App\Actions\Chat\InstallChatbotDefaults;
use App\Actions\Chat\LookupOrderStatus;
use App\Actions\Chat\LookupRepairStatus;
use App\Enums\ChatChannel;
use App\Enums\ChatMode;
use App\Enums\OrderStatus;
use App\Enums\SettingsArea;
use App\Enums\TelegramDirection;
use App\Events\Telegram\TelegramInboxMessage;
use App\Models\ChatRule;
use App\Models\Order;
use App\Models\Page;
use App\Models\Setting;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Rules\BrandSafeText;
use App\Support\Chat\ChatbotSwitch;
use App\Support\Chat\ChatContext;
use App\Support\Chat\ChatReply;
use App\Support\Chat\Engine;
use App\Support\Chat\RuleIndex;
use App\Support\Telegram\Bot\BotSwitch;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

/**
 * Security review of the built-in chat rules added with vat, integration_1c,
 * availability, dealer, complaint, label_ribbon_fit, repair_intake and
 * rfid_info: texts carry no payment brands, contacts or long digit runs, a
 * complaint with an order number never reaches the order lookup, keyword
 * hand-offs do not spam staff, and the menu stays small.
 */
const CDR_USER = 5580001;

function cdrSetup(): void
{
    telegramBotConfigured();
    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    Setting::query()->updateOrCreate(['key' => BotSwitch::KEY], ['group' => SettingsArea::TelegramBot->group(), 'value' => true]);
    Page::factory()->system('privacy')->create(['is_active' => true, 'needs_review' => false]);
    Setting::query()->updateOrCreate(['key' => ChatbotSwitch::TELEGRAM_KEY], ['group' => SettingsArea::Chatbot->group(), 'value' => true]);
    app(InstallChatbotDefaults::class)->handle();
    RuleIndex::bump();
}

function cdrUser(string $locale = 'uz'): TelegramUser
{
    return TelegramUser::factory()->registered()->create(['telegram_id' => CDR_USER, 'locale' => $locale, 'chat_mode' => ChatMode::Bot]);
}

function cdrText(string $text): void
{
    static $id = 95000;

    postTelegramUpdate(telegramPrivateUpdate(++$id, ['text' => $text], CDR_USER))->assertOk();
}

function cdrLastSent(): string
{
    return (string) collect(telegramApiCalls())->where('method', 'sendMessage')->pluck('data.text')->last();
}

function cdrAsk(string $text, string $locale = 'uz'): ChatReply
{
    return app(Engine::class)->respond(new ChatContext(ChatChannel::Telegram, $locale, $text));
}

function cdrKey(ChatReply $reply): ?string
{
    return $reply->ruleId === null ? null : ChatRule::query()->whereKey($reply->ruleId)->value('key');
}

it('installs rule answers and labels without payment brands, contacts, links, long digit runs or banned wording', function () {
    cdrSetup();

    $texts = [];

    foreach (ChatRule::query()->get() as $rule) {
        foreach (['uz', 'ru', 'en'] as $locale) {
            $texts[] = [$rule->key.' answer '.$locale, (string) ($rule->answer[$locale] ?? '')];
            $texts[] = [$rule->key.' label '.$locale, (string) ($rule->params['label'][$locale] ?? '')];
        }
    }

    expect(count($texts))->toBeGreaterThan(60);

    foreach ($texts as [$where, $text]) {
        if ($text === '') {
            continue;
        }

        expect(preg_match('/\\b(payme|click)\\b/i', $text))->toBe(0, "{$where}: payment brand")
            ->and(str_contains($text, '@'))->toBeFalse("{$where}: @")
            ->and(preg_match('/http/i', $text))->toBe(0, "{$where}: link")
            ->and(preg_match('/\d{9,}/', $text))->toBe(0, "{$where}: long digit run")
            ->and(Validator::make(['t' => $text], ['t' => [new BrandSafeText]])->passes())->toBeTrue("{$where}: brand safe");
    }
});

it('keeps the menu at twelve items or fewer after the install', function () {
    cdrSetup();

    expect(ChatRule::query()->where('in_menu', true)->count())->toBeLessThanOrEqual(12);

    $menu = app(Engine::class)->menu(new ChatContext(ChatChannel::Telegram, 'en', ''));

    expect(count($menu->buttons))->toBeLessThanOrEqual(13);
});

it('hands a refund message with an order number to staff without any order lookup', function (string $text, string $locale, string $warning) {
    cdrSetup();
    cdrUser($locale);
    $this->mock(LookupOrderStatus::class)->shouldNotReceive('byNumber', 'byId', 'recent');
    $this->mock(LookupRepairStatus::class)->shouldNotReceive('byNumber', 'byId', 'recent');
    Order::factory()->forPhone('+998901234567')->status(OrderStatus::Processing)->paid()
        ->create(['number' => 'O-2026-0012', 'name' => 'Secret Buyer', 'total' => 987_654_300]);

    $reply = cdrAsk($text, $locale);

    expect($reply->isHandoff())->toBeTrue()
        ->and(cdrKey($reply))->toBe('complaint');

    cdrText($text);

    expect(cdrLastSent())->toContain($warning)
        ->not->toContain('Secret Buyer')
        ->not->toContain('9876543')
        ->not->toContain('O-2026-0012')
        ->not->toContain(e(__('enums.order_status.processing', [], $locale)));
})->with([
    'ru' => ['возврат O-2026-0012', 'ru', 'номер карты'],
    'en' => ['refund O-2026-0012', 'en', 'card numbers'],
    'uz' => ['qaytarish O-2026-0012', 'uz', 'Karta raqami'],
]);

it('sends one staff alert and no system line for five typed keyword hand-offs in a row', function () {
    cdrSetup();
    Event::fake([TelegramInboxMessage::class]);
    $user = cdrUser('ru');
    $typed = ['наличие', 'дилер', 'жалоба', 'не работает', 'в наличии'];

    foreach ($typed as $text) {
        cdrText($text);
    }

    $inbound = TelegramMessage::query()->where('direction', TelegramDirection::In)->orderBy('id')->pluck('text')->all();

    expect($inbound)->toBe($typed)
        ->and($user->fresh()->chat_mode)->toBe(ChatMode::Manager)
        ->and($user->fresh()->unread_count)->toBe(5);
    Event::assertDispatchedTimes(TelegramInboxMessage::class, 1);
});

it('sends a repair complaint to the repair intake hand-off', function (string $text, string $locale) {
    cdrSetup();

    $reply = cdrAsk($text, $locale);

    expect($reply->isHandoff())->toBeTrue()
        ->and(cdrKey($reply))->toBe('repair_intake');
})->with([
    'translit' => ['my printer ne rabotaet', 'uz'],
    'ru scanner' => ['сканер не работает', 'ru'],
]);

it('does not send a warehouse scanner question to the label and ribbon fit rule', function () {
    cdrSetup();

    $reply = cdrAsk('какой сканер подойдёт для склада', 'ru');

    expect(cdrKey($reply))->not->toBe('label_ribbon_fit')
        ->and($reply->isHandoff())->toBeFalse();
});
