<?php

require_once __DIR__.'/../ChatBotHelpers.php';
require_once __DIR__.'/WebChatHelpers.php';

use App\Actions\Chat\AnswerProductSearch;
use App\Actions\Chat\RecordUnmatched;
use App\Enums\ChatChannel;
use App\Enums\ChatMetric;
use App\Enums\ChatMode;
use App\Enums\LeadFlow;
use App\Models\ChatStatDaily;
use App\Models\ChatUnmatched;
use App\Models\TelegramUser;
use App\Models\WebChat;
use App\Support\Chat\ChatContext;
use App\Support\Chat\ChatReply;
use App\Support\Chat\WebChatDialog;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Plan 28 C5a, WC-1 / WC-2: AnswerProductSearch and RecordUnmatched take a
 * ChatSubject. Telegram keys stay byte-identical (bare id); a web chat is
 * "web:{id}" with its own global budgets; stats and the unmatched channel
 * come from the subject.
 */
function wcSearch(TelegramUser|WebChat $subject, string $text = 'QX5501'): ChatReply
{
    $channel = $subject instanceof WebChat ? ChatChannel::Web : ChatChannel::Telegram;

    return app(AnswerProductSearch::class)->handle($subject, new ChatContext($channel, 'en', $text), new ChatReply('prompt', [], null, ChatReply::SEARCH));
}

it('WC1_keeps_the_telegram_limiter_keys_byte_identical', function () {
    wcProduct('QX5501');
    $user = TelegramUser::factory()->registered()->create();

    wcSearch($user);
    app(RecordUnmatched::class)->handle($user, 'en', 'xyzzy plugh');
    $day = today()->format('Ymd');

    expect($user->chatLimiterKey())->toBe((string) $user->id)
        ->and(RateLimiter::attempts('chat-search:user:'.$user->id))->toBe(1)
        ->and(RateLimiter::attempts('chat-search:global'))->toBe(1)
        ->and(RateLimiter::attempts("chatbot-unmatched:chat:{$day}:{$user->id}"))->toBe(1)
        ->and(RateLimiter::attempts("chatbot-unmatched:all:{$day}"))->toBe(1)
        ->and(RateLimiter::attempts('chat-search:global:web'))->toBe(0);
});

it('WC1_telegram_user_5_and_web_chat_5_have_separate_budgets', function () {
    config(['chatbot.search.per_minute_user' => 1]);
    wcProduct('QX5501');
    $user = TelegramUser::factory()->registered()->create(['id' => 5]);
    $chat = WebChat::factory()->create(['id' => 5]);

    expect(wcSearch($chat)->text)->toBe(__('chatbot.search.found', [], 'en'))
        ->and(wcSearch($user)->text)->toContain('QX5501')
        ->and(RateLimiter::attempts('chat-search:user:web:5'))->toBe(1)
        ->and(RateLimiter::attempts('chat-search:user:5'))->toBe(1);
});

it('WC1_61_web_searches_a_minute_leave_the_telegram_search_working', function () {
    wcProduct('QX5501');
    config(['chatbot.search.per_minute_global_web' => 60]);
    $chats = WebChat::factory()->count(61)->create();

    $replies = $chats->map(fn (WebChat $chat): string => wcSearch($chat)->text);

    expect($replies->last())->toBe(__('chatbot.search.limited', [], 'en'))
        ->and($replies->filter(fn (string $text): bool => $text === __('chatbot.search.limited', [], 'en')))->toHaveCount(1)
        ->and(wcSearch(TelegramUser::factory()->registered()->create())->text)->toContain('QX5501');
});

it('WC1_search_stats_go_to_the_subject_channel', function () {
    wcProduct('QX5501');

    wcSearch(WebChat::factory()->create());

    expect(ChatStatDaily::query()->where('channel', ChatChannel::Web->value)->where('metric', ChatMetric::Hit->value)->sum('count'))->toEqual(1)
        ->and(ChatStatDaily::query()->where('channel', ChatChannel::Telegram->value)->count())->toBe(0);
});

it('WC2_stores_a_web_phrase_redacted_with_channel_web', function () {
    $id = app(RecordUnmatched::class)->handle(WebChat::factory()->create(), 'en', 'xyzzy call +998 90 123 45 67 card 8600 1234 5678 9012');
    $row = ChatUnmatched::query()->findOrFail($id);

    expect($row->channel)->toBe(ChatChannel::Web)
        ->and($row->sample)->not->toContain('123')
        ->not->toContain('8600');
});

it('WC2_records_no_phrase_without_consent_banned_in_manager_mode_or_with_an_open_form', function (array $attributes) {
    $chat = WebChat::factory()->create($attributes);

    expect($chat->mayRecordPhrases())->toBeFalse()
        ->and(app(RecordUnmatched::class)->handle($chat, 'en', 'xyzzy plugh'))->toBeNull()
        ->and(ChatUnmatched::query()->count())->toBe(0);
})->with([
    'no consent' => [['consent_at' => null]],
    'banned' => [['banned_at' => now()]],
    'manager mode' => [['mode' => ChatMode::Manager, 'handoff_at' => now()]],
    'open form' => [['dialog' => WebChatDialog::withForm(LeadFlow::Callback)]],
]);

it('WC2_the_301st_web_phrase_a_day_is_not_stored_while_telegram_still_records', function () {
    config(['chatbot.unmatched.per_day_global_web' => 300]);
    $day = today()->format('Ymd');

    foreach (range(1, 300) as $n) {
        RateLimiter::hit("chatbot-unmatched:all:web:{$day}", 86400);
    }

    expect(app(RecordUnmatched::class)->handle(WebChat::factory()->create(), 'en', 'xyzzy plugh'))->toBeNull()
        ->and(app(RecordUnmatched::class)->handle(TelegramUser::factory()->registered()->create(), 'en', 'xyzzy plugh'))->not->toBeNull()
        ->and(ChatUnmatched::query()->sole()->channel)->toBe(ChatChannel::Telegram);
});
