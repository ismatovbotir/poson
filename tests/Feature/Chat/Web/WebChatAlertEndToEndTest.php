<?php

require_once __DIR__.'/WebChatHelpers.php';

use App\Actions\Chat\Web\HandOffWebChat;
use App\Enums\ChatAuthor;
use App\Enums\ChatMode;
use App\Events\Chat\WebChatHandedOff;
use App\Models\WebChat;
use App\Support\Telegram\TelegramLocale;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Plan 28 C5c, WC-13: the hand-off alert end to end (event, queued listener,
 * Telegram sales chat) with Http::fake, sync queue.
 */
beforeEach(function () {
    wcSetup(defaults: false);
    app()->forgetInstance('queue');
    Facade::clearResolvedInstance('queue');
    config(['queue.default' => 'sync']);
    telegramBotConfigured();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    RateLimiter::clear(WebChatHandedOff::LIMIT_KEY);
    Cache::forget(WebChatHandedOff::LIMITED_KEY);
});

function sentToSales(): Collection
{
    return Http::recorded()->filter(fn (array $pair) => str_contains($pair[0]->url(), 'sendMessage'))->map(fn (array $pair) => $pair[0]);
}

it('sends one PII-free alert to the sales chat with the canonical https link when a visitor asks for a manager', function () {
    $chat = WebChat::factory()->withContact('+998901234567', 'Alisher')->create(['first_page' => '/en/secret-page']);
    $chat->messages()->create(['author' => ChatAuthor::Visitor, 'text' => 'my secret text']);
    app('request')->headers->set('host', 'evil.example');

    app(HandOffWebChat::class)->request($chat);
    app(HandOffWebChat::class)->request($chat->fresh());

    $sent = sentToSales();
    expect($sent)->toHaveCount(1);

    /** @var Request $request */
    $request = $sent->first();
    $body = json_encode($request->data());

    expect($request['chat_id'])->toBe('-1001111111111')
        ->and($body)->toContain('W-'.$chat->id)
        ->toContain('https:\/\/poson.example.uz\/admin\/chats\/web\/'.$chat->id)
        ->not->toContain('901234567')->not->toContain('Alisher')->not->toContain('secret')->not->toContain('evil.example');
});

it('sends nothing for a banned chat and nothing without a bot token', function () {
    app(HandOffWebChat::class)->request(WebChat::factory()->banned()->create());
    expect(sentToSales())->toHaveCount(0);

    freshSecrets(['services.telegram.bot_token' => null]);
    app(HandOffWebChat::class)->request(WebChat::factory()->create());
    expect(sentToSales())->toHaveCount(0);
});

it('throttles per chat to one alert per ten minutes', function () {
    $chat = WebChat::factory()->create();

    app(HandOffWebChat::class)->request($chat);
    $chat->fresh()->forceFill(['mode' => ChatMode::Bot, 'handoff_at' => null])->save();
    app(HandOffWebChat::class)->request($chat->fresh());

    expect(sentToSales())->toHaveCount(1);

    $this->travel(11)->minutes();
    $chat->fresh()->forceFill(['mode' => ChatMode::Bot, 'handoff_at' => null])->save();
    app(HandOffWebChat::class)->request($chat->fresh());

    expect(sentToSales())->toHaveCount(2);
});

it('caps alerts at 30 per hour with exactly one limited notice', function () {
    foreach (WebChat::factory()->count(33)->create() as $chat) {
        app(HandOffWebChat::class)->request($chat);
    }

    $texts = sentToSales()->map(fn (Request $r) => $r['text']);

    expect($texts)->toHaveCount(31)
        ->and($texts->filter(fn (string $t) => str_contains($t, e(__('telegram.web_chat.limited', [], TelegramLocale::current()))))->count())->toBe(1);
});
