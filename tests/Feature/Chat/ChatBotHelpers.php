<?php

use App\Actions\Chat\InstallChatbotDefaults;
use App\Actions\Telegram\HandleTelegramUpdate;
use App\Enums\ChatAction;
use App\Enums\ChatMode;
use App\Enums\KeywordMatch;
use App\Enums\SettingsArea;
use App\Models\ChatRule;
use App\Models\ChatRuleKeyword;
use App\Models\Page;
use App\Models\Setting;
use App\Models\TelegramUser;
use App\Support\Chat\ChatbotSwitch;
use App\Support\Chat\RuleIndex;
use App\Support\Telegram\Bot\BotSwitch;
use Illuminate\Support\Facades\Http;

/**
 * Shared helpers of the plan 28 C3/C4 Telegram tests (search, status lookups,
 * lead dialogs). Prefixed "cb" so they never collide with the C1 helpers.
 */
const CB_USER = 5570001;

function cbSetup(): void
{
    telegramBotConfigured();
    Http::fake(['api.telegram.org/*' => fn () => Http::response(['ok' => true, 'result' => ['message_id' => random_int(1000, 9999999)]])]);
    Setting::query()->updateOrCreate(['key' => BotSwitch::KEY], ['group' => SettingsArea::TelegramBot->group(), 'value' => true]);
    Page::factory()->system('privacy')->create(['is_active' => true, 'needs_review' => false]);
    Setting::query()->updateOrCreate(['key' => ChatbotSwitch::TELEGRAM_KEY], ['group' => SettingsArea::Chatbot->group(), 'value' => true]);
    app(InstallChatbotDefaults::class)->handle();
    RuleIndex::bump();
}

function cbUser(array $attributes = [], int $telegramId = CB_USER): TelegramUser
{
    return TelegramUser::factory()->registered()->create(array_merge(['telegram_id' => $telegramId, 'locale' => 'en', 'chat_mode' => ChatMode::Bot], $attributes));
}

function cbNextId(): int
{
    static $id = 90000;

    return ++$id;
}

function cbText(string $text, int $telegramId = CB_USER, ?int $updateId = null): string
{
    return app(HandleTelegramUpdate::class)->handle(telegramPrivateUpdate($updateId ?? cbNextId(), ['text' => $text], $telegramId));
}

function cbPress(string $data, int $telegramId = CB_USER, ?int $updateId = null): string
{
    return app(HandleTelegramUpdate::class)->handle([
        'update_id' => $updateId ?? cbNextId(),
        'callback_query' => [
            'id' => 'cb'.cbNextId(),
            'from' => ['id' => $telegramId, 'is_bot' => false, 'first_name' => 'Ali'],
            'message' => ['message_id' => 5, 'chat' => ['id' => $telegramId, 'type' => 'private']],
            'data' => $data,
        ],
    ]);
}

/**
 * The text of the last message the bot sent (HTML-escaped, as Telegram receives it).
 */
function cbLast(): string
{
    $sent = array_values(array_filter(telegramApiCalls(), fn (array $call) => $call['method'] === 'sendMessage'));

    return (string) (end($sent)['data']['text'] ?? '');
}

function cbSentCount(): int
{
    return count(array_filter(telegramApiCalls(), fn (array $call) => $call['method'] === 'sendMessage'));
}

/**
 * The callback data of the stored button of this type in the user's current answer list.
 */
function cbButton(TelegramUser $user, string $type, int $nth = 0): string
{
    $dialog = $user->fresh()->bot_dialog;
    $found = 0;

    foreach ($dialog['buttons'] ?? [] as $index => $button) {
        if ($button['t'] === $type && $found++ === $nth) {
            return 'chat:b:'.$dialog['gen'].':'.$index;
        }
    }

    throw new RuntimeException("No stored {$type} button #{$nth}.");
}

/**
 * An engine rule with keywords (always active, on every channel).
 *
 * @param  list<string>  $keywords
 * @param  array<string, mixed>  $attributes
 */
function cbRule(string $key, array $keywords, array $attributes = []): ChatRule
{
    $rule = ChatRule::factory()->create(array_merge([
        'key' => $key,
        'name' => ucfirst($key),
        'action' => ChatAction::Answer,
        'answer' => ['uz' => "{$key} uz", 'ru' => "{$key} ru", 'en' => "{$key} en"],
    ], $attributes));

    foreach ($keywords as $phrase) {
        ChatRuleKeyword::factory()->phrase($phrase, KeywordMatch::Word, 20, null)->create(['chat_rule_id' => $rule->id]);
    }

    RuleIndex::bump();

    return $rule;
}

/**
 * Number of SQL statements among the log whose text contains the needle.
 */
function cbQueriesLike(array $log, string $needle): int
{
    return count(array_filter($log, fn (array $query): bool => str_contains($query['query'], $needle)));
}
