<?php

require_once __DIR__.'/../Chat/Web/WebChatHelpers.php';

use App\Enums\ChatMode;
use App\Enums\TaskRunStatus;
use App\Enums\TaskRunTrigger;
use App\Events\Chat\WebChatHandedOff;
use App\Models\MonitoringState;
use App\Models\ScheduledTaskRun;
use App\Models\Setting;
use App\Models\WebChat;
use App\Support\Chat\ChatbotSwitch;
use App\Support\Chat\WebChatBacklog;
use App\Support\Monitoring\ProblemMonitor;
use App\Support\Telegram\Bot\TelegramStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Plan 28 C5d (CB-27, WC-13): the website chat monitoring row and problem.
 */
beforeEach(function () {
    wcSetup(defaults: false);
    // Wednesday noon in Tashkent: inside default working hours.
    $this->travelTo(now('Asia/Tashkent')->next('Wednesday')->setTime(12, 0));
});

function waitingChat(int $minutes, array $attributes = []): WebChat
{
    return WebChat::factory()->create(array_merge([
        'mode' => ChatMode::Manager,
        'handoff_at' => now()->subMinutes($minutes),
        'last_inbound_at' => now()->subMinutes($minutes),
        'last_message_at' => now()->subMinutes($minutes),
    ], $attributes));
}

function chatbotRow(): array
{
    Cache::forget(TelegramStatus::CACHE_KEY);

    return collect(app(TelegramStatus::class)->result()['rows'])->firstWhere('id', 'chatbot');
}

it('counts hand-offs unanswered beyond the limit, ignoring answered, closed, banned and young ones', function () {
    waitingChat(20);
    waitingChat(5);
    waitingChat(30, ['last_staff_reply_at' => now()->subMinutes(10)]);
    waitingChat(30, ['closed_at' => now(), 'conversation_status' => 'closed']);
    waitingChat(30, ['banned_at' => now()]);
    WebChat::factory()->create(['last_message_at' => now()->subMinutes(30)]);

    expect(WebChatBacklog::unanswered())->toBe(1);
});

it('is neutral while the web switch is off and shows counts only', function () {
    Setting::query()->where('key', ChatbotSwitch::WEB_KEY)->update(['value' => false]);
    ChatbotSwitch::forget();

    $row = chatbotRow();

    expect($row['state'])->toBe('neutral')
        ->and(array_keys($row['params']))->toBe(['unanswered', 'minutes', 'unmatched', 'busiest_ip']);
});

it('flags unanswered hand-offs, a hit alert cap and a stale prune, and is ok otherwise', function () {
    ScheduledTaskRun::query()->create(['task' => 'chat:prune', 'trigger' => TaskRunTrigger::Schedule, 'status' => TaskRunStatus::Success, 'started_at' => now()->subHours(3), 'finished_at' => now()->subHours(3)]);
    expect(chatbotRow()['state'])->toBe('ok');

    Cache::put(WebChatHandedOff::LIMITED_KEY, true, 600);
    expect(chatbotRow())->toMatchArray(['state' => 'problem', 'text' => 'alert_cap']);
    Cache::forget(WebChatHandedOff::LIMITED_KEY);

    ScheduledTaskRun::query()->delete();
    expect(chatbotRow())->toMatchArray(['state' => 'problem', 'text' => 'prune_stale']);

    waitingChat(20);
    expect(chatbotRow())->toMatchArray(['state' => 'problem', 'text' => 'unanswered']);
});

it('has the row translated in every language', function (string $locale) {
    foreach (['label', 'ok', 'neutral', 'unanswered', 'alert_cap', 'prune_stale', 'next'] as $key) {
        expect(trans('telegram_status.rows.chatbot.'.$key, [], $locale))->not->toStartWith('telegram_status.');
    }

    expect(trans('telegram.monitoring.events.chat_web_unanswered', [], $locale))->not->toStartWith('telegram.');
})->with(['uz', 'ru', 'en']);

it('sends the chat_web_unanswered problem once in working hours and not outside them', function () {
    freshSecrets(['services.telegram.bot_token' => '123456789:'.str_repeat('M', 35), 'services.telegram.chats.management' => '-1009999999999']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    waitingChat(20);

    ProblemMonitor::using(2)->evaluateWebChats();
    ProblemMonitor::using(2)->evaluateWebChats();

    $sent = Http::recorded()->filter(fn ($pair) => str_contains($pair[0]->url(), 'sendMessage'));
    expect($sent)->toHaveCount(1)
        ->and($sent->first()[0]['text'])->not->toContain('+998');

    $this->travelTo(now('Asia/Tashkent')->next('Sunday')->setTime(3, 0));
    ProblemMonitor::using(2)->evaluateWebChats();

    expect(MonitoringState::query()->find('problem:chat_web_unanswered')->problem_since)->toBeNull();
});
