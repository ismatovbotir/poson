<?php

use App\Actions\Chat\InstallChatbotDefaults;
use App\Actions\Telegram\HandleTelegramUpdate;
use App\Enums\ChatMode;
use App\Enums\RepairStatus;
use App\Enums\SettingsArea;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Models\Repair;
use App\Models\Setting;
use App\Models\TelegramUser;
use App\Support\Chat\ChatbotSwitch;
use App\Support\Chat\RuleIndex;
use App\Support\Telegram\Bot\BotSwitch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Shared helpers of the Plan 28 C3/C4 abuse tests (ChatLookupAbuseTest,
 * ChatSearchAbuseTest, ChatLeadDialogAbuseTest). Not a test file: each test
 * file pulls it in with require_once. Updates go straight through
 * HandleTelegramUpdate (the real transaction, limits and routing); the bot's
 * answers are read from the faked Telegram API.
 */
const LAB_USER = 5580001;

const LAB_PHONE = '+998901112233';

function labSetup(): void
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

/**
 * A registered customer in bot mode (English), by default the one with LAB_PHONE.
 *
 * @param  array<string, mixed>  $attributes
 */
function labUser(array $attributes = []): TelegramUser
{
    return TelegramUser::factory()->registered()->create(array_merge([
        'telegram_id' => LAB_USER,
        'locale' => 'en',
        'chat_mode' => ChatMode::Bot,
        'phone' => LAB_PHONE,
        'registered_at' => now()->subDay(),
    ], $attributes));
}

function labNextId(): int
{
    static $id = 800000;

    return ++$id;
}

/**
 * @return list<array{method: string, data: array<string, mixed>}>
 */
function labSentCalls(): array
{
    return array_values(array_filter(telegramApiCalls(), fn (array $call): bool => $call['method'] === 'sendMessage'));
}

/**
 * Run one update; the last message the bot sent in answer, or null.
 *
 * @param  array<string, mixed>  $update
 * @return array<string, mixed>|null
 */
function labDeliver(array $update): ?array
{
    $before = count(labSentCalls());
    app(HandleTelegramUpdate::class)->handle($update);
    $new = array_slice(labSentCalls(), $before);

    return $new === [] ? null : end($new)['data'];
}

/**
 * @return array<string, mixed>|null
 */
function labTalk(string $text, int $userId = LAB_USER): ?array
{
    return labDeliver(telegramPrivateUpdate(labNextId(), ['text' => $text], $userId));
}

/**
 * A callback_query update (a pressed inline button).
 *
 * @return array<string, mixed>
 */
function labCallbackUpdate(string $data, int $userId = LAB_USER): array
{
    return [
        'update_id' => labNextId(),
        'callback_query' => [
            'id' => 'cb'.labNextId(),
            'from' => ['id' => $userId, 'is_bot' => false, 'first_name' => 'Ali'],
            'message' => ['message_id' => 5, 'chat' => ['id' => $userId, 'type' => 'private']],
            'data' => $data,
        ],
    ];
}

/**
 * @return array<string, mixed>|null
 */
function labPress(string $data, int $userId = LAB_USER): ?array
{
    return labDeliver(labCallbackUpdate($data, $userId));
}

/**
 * The reply text, empty when the bot said nothing.
 *
 * @param  array<string, mixed>|null  $reply
 */
function labText(?array $reply): string
{
    return (string) ($reply['text'] ?? '');
}

/**
 * What the customer sees for a built-in text (escaped as the sending job does).
 */
function labLang(string $key, array $replace = []): string
{
    return e(__($key, $replace, 'en'));
}

/**
 * The callback_data of every button of a reply.
 *
 * @param  array<string, mixed>|null  $reply
 * @return list<string>
 */
function labCallbacks(?array $reply): array
{
    $markup = $reply['reply_markup'] ?? null;
    $markup = is_string($markup) ? json_decode($markup, true) : $markup;

    return collect($markup['inline_keyboard'] ?? [])->flatten(1)->pluck('callback_data')->filter()->values()->all();
}

/**
 * "chat:b:G:I" of the stored button of a type in the customer's current list.
 */
function labStoredButton(string $type, int $userId = LAB_USER): string
{
    $dialog = TelegramUser::query()->where('telegram_id', $userId)->firstOrFail()->bot_dialog;

    foreach ($dialog['buttons'] ?? [] as $index => $button) {
        if ($button['t'] === $type) {
            return 'chat:b:'.$dialog['gen'].':'.$index;
        }
    }

    throw new RuntimeException("No stored {$type} button.");
}

/**
 * Number of queries on a table made while the closure runs, and its result.
 *
 * @return array{0: mixed, 1: array<string, int>} result, table => count
 */
function labQueries(Closure $run, string ...$tables): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $result = $run();
    DB::disableQueryLog();

    $counts = [];

    foreach ($tables as $table) {
        $counts[$table] = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => preg_match('/\b(from|join)\s+[`"]?'.preg_quote($table, '/').'[`"]?(\s|$)/i', $query['query']) === 1)
            ->count();
    }

    return [$result, $counts];
}

/**
 * An order of the customer with distinctive private values.
 *
 * @param  array<string, mixed>  $attributes
 */
function labOrder(array $attributes = []): Order
{
    return Order::factory()->totalling(5_551_234_00, 87_654_00)->create(array_merge([
        'number' => 'O-2026-0001',
        'phone' => LAB_PHONE,
        'name' => 'Privatniy Marker Familiya',
        'created_at' => now()->subMonth(),
    ], $attributes));
}

/**
 * A repair of the customer with distinctive private values in every field the bot must not show.
 *
 * @param  array<string, mixed>  $attributes
 */
function labRepair(array $attributes = []): Repair
{
    return Repair::factory()->estimated(9_876_543_00)->create(array_merge([
        'number' => 'R-2026-000123',
        'contact_phone' => LAB_PHONE,
        'contact_name' => 'Kontakt Marker Ismi',
        'company' => 'MARKCOMPANY MChJ',
        'serial_number' => 'SN-MARK-7731',
        'accessories' => 'MARKACCESSORY cable',
        'problem' => 'MARKPROBLEM does not read',
        'intake_condition' => 'MARKCONDITION scratched',
        'diagnosis' => 'MARKDIAGNOSIS board',
        'work_done' => 'MARKWORK soldered',
        'parts_used' => 'MARKPARTS chip',
        'warranty_reference' => 'MARKWARRANTY-55',
        'final_amount' => 7_654_321_00,
        'status' => RepairStatus::InRepair,
    ], $attributes));
}

/**
 * Words that must never appear in a status reply.
 *
 * @return list<string>
 */
function labPrivateMarkers(): array
{
    return ['Privatniy', 'Familiya', 'Marker', 'MARK', 'SN-MARK', '5 551', '5551234', '87 654', '9 876', '9876543', '7 654', '7654321', '998909998877', '8877'];
}

/**
 * A published product the bot's search finds by its model.
 *
 * @param  array<string, mixed>  $attributes
 */
function labProduct(string $model, array $attributes = []): Product
{
    return Product::factory()->create(array_merge([
        'model' => $model,
        'sku' => 'SKU-'.$model,
        'name' => ['uz' => 'Uz '.$model, 'ru' => 'Ru '.$model, 'en' => 'Scanner '.$model],
        'price' => 125_000_000,
        'price_dealer' => 7_123_456_00,
        'price_wholesale' => 6_234_567_00,
    ], $attributes));
}
