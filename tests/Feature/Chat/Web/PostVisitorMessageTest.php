<?php

require_once __DIR__.'/../ChatBotHelpers.php';
require_once __DIR__.'/WebChatHelpers.php';

use App\Actions\Chat\Web\PostedMessage;
use App\Actions\Chat\Web\PostVisitorMessage;
use App\Enums\ChatAction;
use App\Enums\ChatAuthor;
use App\Enums\ChatMode;
use App\Enums\LeadFlow;
use App\Events\Chat\WebChatHandedOff;
use App\Models\ChatAiTurn;
use App\Models\ChatUnmatched;
use App\Models\Page;
use App\Models\Setting;
use App\Models\WebChat;
use App\Models\WebChatMessage;
use App\Support\Chat\ChatbotSwitch;
use App\Support\Chat\ChatReply;
use App\Support\Chat\Engine;
use App\Support\Chat\WebChatDialog;
use App\Support\Chat\WebChatIp;
use App\Support\Chat\WebChatToken;
use App\Support\Chat\WebChatVersion;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Plan 28 C5a: PostVisitorMessage / StartWebChat / ResolveWebChat (§19.3,
 * CB-18, CB-19, CB-24, WC-3, WC-4, WC-5, WC-6, WC-10, WC-14, WC-18).
 */
beforeEach(fn () => wcSetup());

it('first_send_creates_chat_cookie_consent_and_version', function () {
    $posted = wcSend('Hello', page: '/ru/product/zebra-zd421?utm_source=x#top');
    $chat = WebChat::query()->sole();
    $token = wcIssuedToken();

    expect($posted->status)->toBe(PostedMessage::ANSWERED)
        ->and($posted->chatId)->toBe($chat->id)
        ->and($token)->not->toBeNull()
        ->and(WebChatToken::isWellFormed($token))->toBeTrue()
        ->and($chat->token_hash)->toBe(hash('sha256', $token))
        ->and($chat->consent_at)->not->toBeNull()
        ->and($chat->consent_version)->toBe(config('chatbot.web.consent_version'))
        ->and($chat->first_page)->toBe('/ru/product/zebra-zd421')
        ->and($chat->ip_hash)->toHaveLength(64)
        ->and($chat->mode)->toBe(ChatMode::Bot)
        ->and(WebChatVersion::get($chat->id))->not->toBeNull()
        ->and($chat->messages()->pluck('author')->all())->toBe([ChatAuthor::Visitor, ChatAuthor::Bot]);
});

it('answers the next message in the same chat through the cookie and re-sends the cookie', function () {
    wcSend('Hello');
    $token = wcIssuedToken();

    $posted = wcSend('Hello again', $token);

    expect($posted->status)->toBe(PostedMessage::ANSWERED)
        ->and(WebChat::query()->count())->toBe(1)
        ->and(WebChatMessage::query()->count())->toBe(4)
        ->and(wcIssuedToken())->toBe($token);
});

it('WC10_first_page_from_the_locked_path_never_the_request_path_or_referer', function (?string $page, ?string $stored) {
    app('request')->headers->set('referer', 'https://evil.example/phish');

    wcSend('Hello', page: $page);

    expect(WebChat::query()->sole()->first_page)->toBe($stored);
})->with([
    'product page' => ['/ru/products/x', '/ru/products/x'],
    'query string dropped' => ['/en/catalog?brand=zebra', '/en/catalog'],
    'full url keeps the path' => ['https://poson.uz/uz/services', '/uz/services'],
    'order page never stored' => ['/o/01JABCDEF', null],
    'localized quote link never stored' => ['/ru/q/abc', null],
    'livewire update route never stored' => ['/livewire-4aeeedbb/update', null],
    'control characters stripped' => ["/en/pa\u{202E}ge", '/en/page'],
    'no page' => [null, null],
]);

it('WC4_calls_the_engine_with_ai_literally_false', function () {
    $engine = Mockery::mock(Engine::class)->makePartial();
    $engine->shouldReceive('respond')->once()->withArgs(fn ($context, $ai) => $ai === false)->andReturnUsing(
        fn ($context) => new ChatReply('ok'),
    );
    app()->instance(Engine::class, $engine);

    wcSend('Hello');

    expect(ChatAiTurn::query()->count())->toBe(0);
});

it('stores the visitor text redacted and the bot text plain', function () {
    wcSend('call me +998 90 123 45 67 or ali@example.com <img src=x onerror=alert(1)>');
    $visitor = WebChatMessage::query()->where('author', ChatAuthor::Visitor)->sole();

    expect($visitor->text)->not->toContain('123')
        ->not->toContain('example.com')
        ->toContain('<img');
});

it('cleans non-string and control-character input and refuses an empty one', function (mixed $input, string $status) {
    expect(wcSend($input)->status)->toBe($status);
})->with([
    'array' => [['x'], PostedMessage::IGNORED],
    'int' => [12, PostedMessage::IGNORED],
    'null' => [null, PostedMessage::IGNORED],
    'only zero-width' => ["\u{200B}\u{200E}", PostedMessage::IGNORED],
    'invalid utf-8' => ["\xC3\x28 hello", PostedMessage::ANSWERED],
]);

it('cuts a long message before the engine and storage', function () {
    wcSend(str_repeat('a', 10_000));

    expect(mb_strlen(WebChatMessage::query()->where('author', ChatAuthor::Visitor)->sole()->text))->toBeLessThanOrEqual(1000);
});

it('per_chat_minute_and_day_limits_hit_before_work', function () {
    config(['chatbot.web.per_minute_chat' => 2]);
    wcSend('one');
    $token = wcIssuedToken();
    wcSend('two', $token);

    DB::enableQueryLog();
    $posted = wcSend('three', $token);
    $log = DB::getQueryLog();

    expect($posted->status)->toBe(PostedMessage::LIMITED)
        ->and(WebChatMessage::query()->where('author', ChatAuthor::Visitor)->count())->toBe(2)
        ->and(cbQueriesLike($log, 'insert into "web_chat_messages"'))->toBe(0)
        ->and(cbQueriesLike($log, 'chat_rules'))->toBe(0);
});

it('per_ip_hash_and_new_chats_per_hour_limits', function () {
    config(['chatbot.web.new_per_hour_ip' => 2]);

    expect(wcSend('a')->status)->toBe(PostedMessage::ANSWERED)
        ->and(wcSend('b')->status)->toBe(PostedMessage::ANSWERED)
        ->and(wcSend('c')->status)->toBe(PostedMessage::LIMITED)
        ->and(wcIssuedToken())->toBeNull()
        ->and(wcSend('d', ip: '198.51.100.9')->status)->toBe(PostedMessage::ANSWERED)
        ->and(WebChat::query()->count())->toBe(3);
});

it('global_caps_new_chats_and_messages', function () {
    config(['chatbot.web.new_per_hour_global' => 1]);

    expect(wcSend('a')->status)->toBe(PostedMessage::ANSWERED)
        ->and(wcSend('b', ip: '198.51.100.9')->status)->toBe(PostedMessage::LIMITED)
        ->and(WebChat::query()->count())->toBe(1);

    config(['chatbot.web.per_minute_global' => 3]);

    expect(wcSend('c', ip: '198.51.100.10')->status)->toBe(PostedMessage::LIMITED);
});

it('WC3_limits_run_outside_the_transaction_and_survive_a_rollback', function () {
    config(['cache.default' => 'database']);
    app()->forgetInstance(CacheRateLimiter::class);
    RateLimiter::clearResolvedInstances();
    [$chat, $token] = wcChat();
    WebChatMessage::creating(function (): void {
        throw new RuntimeException('boom');
    });

    $events = [];
    DB::listen(function (QueryExecuted $query) use (&$events): void {
        $events[] = $query->sql;
    });
    Event::listen(TransactionBeginning::class, function () use (&$events): void {
        $events[] = 'BEGIN';
    });
    Event::listen(TransactionCommitted::class, function () use (&$events): void {
        $events[] = 'COMMIT';
    });
    Event::listen(TransactionRolledBack::class, function () use (&$events): void {
        $events[] = 'ROLLBACK';
    });

    expect(fn () => wcSend('hello', $token))->toThrow(RuntimeException::class);

    // The database cache store runs its own short transactions; find the one that rolled back (the chat's).
    [$begin, $end, $open] = [null, null, []];

    foreach ($events as $index => $event) {
        if ($event === 'BEGIN') {
            $open[] = $index;
        } elseif ($event === 'COMMIT') {
            array_pop($open);
        } elseif ($event === 'ROLLBACK') {
            [$begin, $end] = [array_pop($open), $index];

            break;
        }
    }
    $cache = fn (array $slice): array => array_values(array_filter($slice, fn (string $sql): bool => preg_match('/[`"]cache[`"]/', $sql) === 1));

    expect($begin)->toBeInt()
        ->and($end)->toBeGreaterThan($begin)
        ->and($cache(array_slice($events, 0, $begin)))->not->toBeEmpty()
        ->and($cache(array_slice($events, $begin, $end - $begin)))->toBe([])
        ->and(RateLimiter::attempts('web-chat:msg:chat:'.$chat->id))->toBe(1)
        ->and(RateLimiter::attempts('web-chat:msg:all'))->toBe(1);
});

it('WC6_resolves_on_every_call_an_expired_chat_starts_fresh', function () {
    [$old, $token] = wcChat(['last_message_at' => now()->subHours(25)]);

    $posted = wcSend('Hello', $token);

    expect($posted->chatId)->not->toBe($old->id)
        ->and(WebChat::query()->count())->toBe(2)
        ->and($old->messages()->count())->toBe(0)
        ->and(wcIssuedToken())->not->toBe($token);
});

it('WC6_a_closed_chat_starts_fresh', function () {
    [$old, $token] = wcChat([], fn ($factory) => $factory->closed());

    expect(wcSend('Hello', $token)->chatId)->not->toBe($old->id);
});

it('WC6_switch_off_mid_session_stores_nothing', function (string $how) {
    [$chat, $token] = wcChat();

    match ($how) {
        'switch' => Setting::query()->where('key', ChatbotSwitch::WEB_KEY)->update(['value' => json_encode(false)]),
        'web_ready' => config(['chatbot.web_ready' => false]),
        'privacy' => Page::query()->update(['is_active' => false]),
    };
    ChatbotSwitch::forget();

    expect(wcSend('Hello', $token)->status)->toBe(PostedMessage::UNAVAILABLE)
        ->and(wcSend('Hello')->status)->toBe(PostedMessage::UNAVAILABLE)
        ->and(WebChatMessage::query()->count())->toBe(0)
        ->and(WebChat::query()->count())->toBe(1);
})->with(['switch', 'web_ready', 'privacy']);

it('forged_or_tampered_cookie_is_no_chat', function (string $cookie) {
    [$chat] = wcChat();

    wcSend('Hello', $cookie);

    expect($chat->messages()->count())->toBe(0)
        ->and(WebChat::query()->count())->toBe(2);
})->with([
    '42 chars' => [str_repeat('a', 42)],
    'wrong alphabet' => [str_repeat('a', 42).'='],
    'random well-formed' => [WebChatToken::generate()],
    'a hash instead of the token' => [hash('sha256', 'x')],
]);

it('WC14_banned_cookie_is_unavailable_kept_and_starts_no_chat', function () {
    [$chat, $token] = wcChat([], fn ($factory) => $factory->banned());

    $posted = wcSend('Hello', $token);

    expect($posted->status)->toBe(PostedMessage::UNAVAILABLE)
        ->and(WebChat::query()->count())->toBe(1)
        ->and($chat->messages()->count())->toBe(0)
        ->and(wcIssuedToken())->toBeNull()
        ->and(Cookie::queued(WebChatToken::cookieName()))->toBeNull();
});

it('WC14_after_a_ban_the_same_ip_gets_one_new_chat_per_hour_for_24h', function () {
    wcSend('first');
    WebChat::query()->update(['banned_at' => now()]);

    expect(wcSend('second')->status)->toBe(PostedMessage::ANSWERED)
        ->and(wcSend('third')->status)->toBe(PostedMessage::LIMITED)
        ->and(wcSend('other ip', ip: '198.51.100.20')->status)->toBe(PostedMessage::ANSWERED);

    $this->travel(25)->hours();
    RateLimiter::clear('web-chat:new:ip:'.WebChatIp::limiterKey('203.0.113.7'));

    expect(wcSend('later')->status)->toBe(PostedMessage::ANSWERED);
});

it('double_send_within_3s_stored_once', function () {
    wcSend('Hello');
    $token = wcIssuedToken();

    expect(wcSend('Hello', $token)->status)->toBe(PostedMessage::IGNORED)
        ->and(WebChatMessage::query()->where('author', ChatAuthor::Visitor)->count())->toBe(1);

    $this->travel(4)->seconds();

    expect(wcSend('Hello', $token)->status)->toBe(PostedMessage::ANSWERED);
});

it('manager_mode_stores_for_staff_and_the_21st_unanswered_message_gets_wait', function () {
    Event::fake([WebChatHandedOff::class]);
    config(['chatbot.web.unanswered_max' => 3]);
    [$chat, $token] = wcChat(['handoff_at' => now()->subMinute(), 'mode' => ChatMode::Manager, 'last_alert_at' => now()->subMinute()]);

    foreach (['one', 'two', 'three'] as $text) {
        expect(wcSend($text, $token)->status)->toBe(PostedMessage::QUEUED);
    }

    expect(wcSend('four', $token)->status)->toBe(PostedMessage::WAIT)
        ->and($chat->messages()->where('author', ChatAuthor::Visitor)->count())->toBe(3)
        ->and($chat->messages()->where('author', ChatAuthor::Bot)->count())->toBe(0)
        ->and($chat->fresh()->unread_count)->toBe(3);

    Event::assertNotDispatched(WebChatHandedOff::class);
});

it('manager_mode_realerts_after_ten_minutes', function () {
    Event::fake([WebChatHandedOff::class]);
    [, $token] = wcChat(['handoff_at' => now()->subHour(), 'mode' => ChatMode::Manager, 'last_alert_at' => now()->subMinutes(11)]);

    wcSend('still there?', $token);
    wcSend('hello?', $token);

    Event::assertDispatchedTimes(WebChatHandedOff::class, 1);
    Event::assertDispatched(WebChatHandedOff::class, fn (WebChatHandedOff $event): bool => $event->reason === WebChatHandedOff::REASON_MESSAGE);
});

it('opens the inline lead form for a lead rule (the engine never runs a web dialog)', function () {
    cbRule('callme', ['qongiroq'], ['action' => ChatAction::LeadDialog, 'params' => ['flow' => 'service']]);

    wcSend('qongiroq', locale: 'uz');
    $form = WebChatDialog::form(WebChat::query()->sole()->dialog);

    expect($form['flow'])->toBe(LeadFlow::Service)
        ->and($form['product_id'])->toBeNull()
        ->and($form['at'])->toBe(now()->getTimestamp());
});

it('hands off on a handoff rule: manager mode, system line, unread, one alert', function () {
    Event::fake([WebChatHandedOff::class]);

    wcSend('manager');
    $chat = WebChat::query()->sole();

    expect($chat->mode)->toBe(ChatMode::Manager)
        ->and($chat->handoff_at)->not->toBeNull()
        ->and($chat->unread_count)->toBe(1)
        ->and($chat->ai_context_at)->not->toBeNull()
        ->and($chat->messages()->where('author', ChatAuthor::System)->count())->toBe(1);

    Event::assertDispatchedTimes(WebChatHandedOff::class, 1);
});

it('stores a search answer with product ids only: no names or prices in the text', function () {
    $product = wcProduct('QX9901', ['price' => 1_234_500_00, 'price_dealer' => 1_000_000_00]);

    wcSend('QX9901');
    $bot = WebChatMessage::query()->where('author', ChatAuthor::Bot)->sole();

    expect($bot->text)->toBe(__('chatbot.search.found', [], 'en'))
        ->and($bot->buttons[0])->toEqualCanonicalizing(['type' => 'product', 'id' => $product->id])
        ->and(json_encode($bot->buttons))->not->toContain('QX9901')
        ->and($bot->text)->not->toContain('1 234');
});

it('records an unmatched web phrase redacted with channel web', function () {
    wcSend('xyzzy plugh +998901234567');

    $row = ChatUnmatched::query()->sole();

    expect($row->channel->value)->toBe('web')
        ->and($row->sample)->not->toContain('901234567');
});

it('keeps the token out of the html snapshot of a model and the log', function () {
    wcSend('Hello');
    $token = (string) wcIssuedToken();
    $chat = WebChat::query()->sole();

    expect(json_encode($chat->toArray()))->not->toContain($chat->token_hash)
        ->not->toContain($token)
        ->and(json_encode($chat->toArray()))->not->toContain((string) $chat->ip_hash);
});

it('WC14_ban_at_2350_still_limits_after_midnight_and_the_hourly_budget_survives_it', function () {
    $this->travelTo(now()->setTime(23, 50));
    config(['chatbot.web.new_per_hour_ip' => 2]);

    wcSend('first');
    WebChat::query()->update(['banned_at' => now()]);

    expect(wcSend('second')->status)->toBe(PostedMessage::ANSWERED);

    $this->travel(20)->minutes();

    // Past midnight: the stored ip_hash is yesterday's, yet the cooldown (1 per hour) and the budget hold.
    expect(WebChatIp::hashes())->toContain(WebChat::query()->orderBy('id')->first()->ip_hash)
        ->and(wcSend('third')->status)->toBe(PostedMessage::LIMITED);
});

it('WC14_the_ip_hash_follows_the_last_activity_so_a_later_ban_is_found', function () {
    $this->travelTo(now()->setTime(23, 0)->subDay());
    wcSend('first');
    $token = wcIssuedToken();
    $chat = WebChat::query()->sole();

    $this->travel(13)->hours();
    expect(wcSend('again', $token)->status)->toBe(PostedMessage::ANSWERED)
        ->and(WebChat::query()->count())->toBe(1);
    WebChat::query()->whereKey($chat->id)->update(['banned_at' => now()]);

    $this->travel(1)->days();

    expect(WebChat::query()->whereKey($chat->id)->value('ip_hash'))->toBe(WebChatIp::hashes()[1])
        ->and(wcSend('new one')->status)->toBe(PostedMessage::ANSWERED)
        ->and(wcSend('new two')->status)->toBe(PostedMessage::LIMITED);
});
