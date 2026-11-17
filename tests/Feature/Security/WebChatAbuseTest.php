<?php

require_once __DIR__.'/../Site/Chat/WidgetHelpers.php';

use App\Actions\Chat\AnswerProductSearch;
use App\Actions\Chat\RecordUnmatched;
use App\Actions\Chat\SaveChatbotSettings;
use App\Actions\Chat\Web\HandOffWebChat;
use App\Actions\Chat\Web\PostedMessage;
use App\Actions\Chat\Web\PruneWebChats;
use App\Actions\Chat\Web\ResolveWebChat;
use App\Actions\Chat\Web\SendWebChatReply;
use App\Actions\Telegram\InboxRefused;
use App\Enums\ChatAuthor;
use App\Enums\ChatChannel;
use App\Enums\ChatMode;
use App\Enums\LeadFlow;
use App\Enums\LeadType;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Events\Chat\WebChatHandedOff;
use App\Events\LeadCreated;
use App\Livewire\Admin\Chat\Web\Show;
use App\Livewire\Admin\Telegram\Chats\Index;
use App\Livewire\Site\Chat\Widget;
use App\Livewire\Site\LeadForm;
use App\Models\ChatAiTurn;
use App\Models\ChatUnmatched;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Setting;
use App\Models\TelegramUser;
use App\Models\User;
use App\Models\WebChat;
use App\Models\WebChatMessage;
use App\Services\Ai\ChatModel;
use App\Support\Chat\AiSwitch;
use App\Support\Chat\ChatbotSwitch;
use App\Support\Chat\ChatContext;
use App\Support\Chat\ChatReply;
use App\Support\Chat\WebChatDialog;
use App\Support\Chat\WebChatIp;
use App\Support\Chat\WebChatSwitch;
use App\Support\Chat\WebChatToken;
use App\Support\Chat\WebChatVersion;
use App\Support\ForeignPhoneLimit;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Plan 28 C5 abuse cases (§20, "Abuse cases", 1-25), in-process, test DB,
 * Http::preventStrayRequests (wcSetup) and Queue::fake. One test (or
 * dataset) per numbered case; helpers are prefixed "wab".
 */
beforeEach(fn () => wcSetup());

afterEach(function () {
    Model::preventLazyLoading(false);
    Model::handleLazyLoadingViolationUsing(null);
});

/**
 * A chat cookie as the browser sends it back: encrypted with the cookie's own name as prefix.
 */
function wabCookie(string $value, string $name = 'poson_chat'): string
{
    return Crypt::encryptString(CookieValuePrefix::create($name, Crypt::getKey()).$value);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function wabHandedOff(array $attributes = [], ?User $manager = null): WebChat
{
    $factory = WebChat::factory()->handedOff();

    return ($manager !== null ? $factory->assignedTo($manager) : $factory)->create($attributes);
}

/**
 * A chat age: last message and creation this long ago.
 *
 * @param  array<string, mixed>  $attributes
 */
function wabPruneChat(string $age, array $attributes = []): WebChat
{
    $at = now()->sub($age);
    $chat = WebChat::factory()->create(array_merge(['last_message_at' => $at, 'created_at' => $at], $attributes));
    $chat->messages()->create(['author' => ChatAuthor::Visitor, 'text' => 'hello']);

    return $chat;
}

it('C5 abuse 1: a page view as an anonymous visitor runs no chat query, sets no chat cookie and does not mount the widget', function () {
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response = $this->get('/en')->assertOk();
    $html = $response->getContent();

    expect(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'web_chat')))->toBe([])
        ->and(collect($response->headers->getCookies())->map->getName()->all())->not->toContain(WebChatToken::cookieName())
        ->and(WebChat::query()->count())->toBe(0)
        ->and($html)->toContain('<template x-if="mounted">')
        ->toContain('&quot;locale&quot;:&quot;&quot;')
        ->toContain('mounted: false')
        ->not->toContain('x-init="$wire.__lazyLoad(');
});

it('C5 abuse 2: a plain, forged, value-swapped or short cookie is no chat and nothing tells which check failed', function (string $kind) {
    [$chat, $token] = wcChat();
    Route::middleware('web')->get('/_wab/who', fn () => response()->json(['chat' => app(ResolveWebChat::class)->handle()->chat?->id]));

    $cookie = match ($kind) {
        'plain token' => $token,
        'forged well-formed' => wabCookie(WebChatToken::generate()),
        'value swapped from poson_cart' => wabCookie($token, 'poson_cart'),
        '42 chars' => wabCookie(substr($token, 0, 42)),
        'not a ciphertext' => 'garbage',
        'a hash of the token' => wabCookie(hash('sha256', $token)),
    };

    $forged = $this->withUnencryptedCookie('poson_chat', $cookie)->get('/_wab/who');
    $none = $this->get('/_wab/who');
    $genuine = $this->withUnencryptedCookie('poson_chat', wabCookie($token))->get('/_wab/who');

    expect($forged->json())->toBe(['chat' => null])
        ->and($forged->getStatusCode())->toBe($none->getStatusCode())
        ->and($forged->getContent())->toBe($none->getContent())
        ->and($genuine->json())->toBe(['chat' => $chat->id]);

    // The visitor path answers like a visitor without a cookie, and the real chat stays untouched.
    $raw = match ($kind) {
        '42 chars' => substr($token, 0, 42),
        'a hash of the token' => hash('sha256', $token),
        default => WebChatToken::generate(),
    };
    $posted = wcSend('Hello', $raw);

    expect($posted->status)->toBe(PostedMessage::ANSWERED)
        ->and($chat->messages()->count())->toBe(0);
})->with(['plain token', 'forged well-formed', 'value swapped from poson_cart', '42 chars', 'not a ciphertext', 'a hash of the token']);

it('C5 abuse 3: no widget method or property takes a chat id or token, so visitor B cannot reach visitor A\'s chat', function () {
    [$a, $tokenA] = wcChat();
    WebChatMessage::factory()->create(['web_chat_id' => $a->id, 'author' => ChatAuthor::Visitor, 'text' => 'Secret of visitor A']);
    [, $tokenB] = wcChat();

    foreach ((new ReflectionClass(Widget::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== Widget::class) {
            continue;
        }

        foreach ($method->getParameters() as $parameter) {
            expect(strtolower($parameter->getName()))->not->toContain('chat')->not->toContain('token');
        }
    }

    $widget = wgWidget($tokenB)->assertDontSee('Secret of visitor A');

    expect(array_keys($widget->instance()->all()))->not->toContain('chatId')->not->toContain('token')
        ->and((new ReflectionMethod(Widget::class, 'refresh'))->getNumberOfParameters())->toBe(0);

    // Naming A's chat id in a property update or a method argument changes nothing.
    expect(fn () => $widget->set('chatId', $a->id))->toThrow(Exception::class, 'not found');
    $widget->call('send', $a->id)->call('press', $a->id)->call('topic', $a->id)->call('refresh')->assertDontSee('Secret of visitor A');

    expect($a->messages()->count())->toBe(1)
        ->and(WebChatToken::hash($tokenA))->toBe($a->token_hash);
});

it('C5 abuse 4: tampering with the locked locale, mode or seenVersion is refused and an unknown locale falls back to the default', function (string $property, mixed $value) {
    wgWidget()->set($property, $value);
})->with([
    'locale' => ['locale', 'xx'],
    'mode' => ['mode', 'manager'],
    'seenVersion' => ['seenVersion', 'forged'],
    'pending' => ['pending', true],
    'page' => ['page', '/o/01HABC'],
])->throws(CannotUpdateLockedPropertyException::class);

it('C5 abuse 4: a locale xx at mount becomes the default locale', function () {
    expect(wgWidget(locale: 'xx')->get('locale'))->toBe((string) config('app.locale'));
});

it('C5 abuse 5: hostile send input is refused or cleaned and every text (widget, Show, inbox, alert) is escaped', function () {
    Event::fake([WebChatHandedOff::class]);
    config(['chatbot.web.per_minute_chat' => 100]);
    $xss = '<img src=x onerror=alert(1)>';

    expect(wcSend(['a'])->status)->toBe(PostedMessage::IGNORED)
        ->and(wcSend(12)->status)->toBe(PostedMessage::IGNORED)
        ->and(wcSend("\u{200B}\u{202E}")->status)->toBe(PostedMessage::IGNORED)
        ->and(WebChat::query()->count())->toBe(0);

    wcSend($xss);
    $token = (string) wcIssuedToken();
    wcSend(str_repeat('a', 10_240), $token);
    wcSend("\xC3\x28 bad bytes", $token);
    wcSend("RTL\u{202E}over\u{200B}ride", $token);

    $stored = WebChatMessage::query()->where('author', ChatAuthor::Visitor)->orderBy('id')->pluck('text');

    expect($stored)->toHaveCount(4)
        ->and(mb_strlen($stored[1]))->toBeLessThanOrEqual((int) config('chatbot.web.message_max'))
        ->and($stored->every(fn (string $text): bool => mb_check_encoding($text, 'UTF-8')))->toBeTrue()
        ->and($stored[3])->toBe('RTLoverride');

    // Hand off, leave a hostile name, and let staff answer with markup too.
    $chat = WebChat::query()->sole();
    wcRequest($token);
    app(HandOffWebChat::class)->request($chat);
    wgWidget($token)->set('contactName', $xss)->set('contactPhone', '90 123 45 67')->call('saveContact')->assertHasNoErrors();
    $chat->refresh();
    app(SendWebChatReply::class)->handle($chat, staffUser(), $xss.' from staff');

    $widgetHtml = wgWidget($token)->html();
    $showHtml = Livewire::actingAs(staffUser())->test(Show::class, ['webChat' => $chat])->html();
    $inboxHtml = Livewire::actingAs(staffUser())->test(Index::class)->html();

    foreach ([$widgetHtml, $showHtml] as $html) {
        expect($html)->not->toContain('<img src=x')->toContain('&lt;img src=x onerror=alert(1)&gt;');
    }

    expect($inboxHtml)->not->toContain('<img src=x');

    $alert = (new WebChatHandedOff($chat->id))->telegramMessage('en');
    expect($alert->html)->not->toContain('onerror')->not->toContain('<img');
});

it('C5 abuse 6: the 16th message a minute per chat, the 61st per IP hash and the 601st overall are limited and store nothing', function (string $limit) {
    $stored = fn (): int => WebChatMessage::query()->where('author', ChatAuthor::Visitor)->count();

    if ($limit === 'chat') {
        [, $token] = wcChat();

        foreach (range(1, 15) as $n) {
            expect(wcSend("message {$n}", $token)->status)->toBe(PostedMessage::ANSWERED);
        }

        expect(wcSend('message 16', $token)->status)->toBe(PostedMessage::LIMITED)->and($stored())->toBe(15);
    }

    if ($limit === 'ip') {
        $tokens = array_map(fn () => wcChat()[1], range(1, 61));

        foreach (array_slice($tokens, 0, 60) as $token) {
            expect(wcSend('hello', $token)->status)->toBe(PostedMessage::ANSWERED);
        }

        expect(wcSend('hello', $tokens[60])->status)->toBe(PostedMessage::LIMITED)->and($stored())->toBe(60);
    }

    if ($limit === 'global') {
        foreach (range(1, 600) as $ignored) {
            RateLimiter::hit('web-chat:msg:all', 60);
        }

        [, $token] = wcChat();

        expect(wcSend('hello', $token, ip: '198.51.100.77')->status)->toBe(PostedMessage::LIMITED)
            ->and(wcSend('hello', ip: '198.51.100.78')->status)->toBe(PostedMessage::LIMITED)
            ->and($stored())->toBe(0)
            ->and(WebChat::query()->count())->toBe(1);
    }

    if ($limit === 'rollback') {
        config(['cache.default' => 'database']);
        app()->forgetInstance(CacheRateLimiter::class);
        RateLimiter::clearResolvedInstances();
        [$chat, $token] = wcChat();
        WebChatMessage::creating(function (): void {
            throw new RuntimeException('forced rollback');
        });

        expect(fn () => wcSend('hello', $token))->toThrow(RuntimeException::class)
            ->and(RateLimiter::attempts('web-chat:msg:chat:'.$chat->id))->toBe(1)
            ->and(RateLimiter::attempts('web-chat:msg:all'))->toBe(1)
            ->and($stored())->toBe(0);
    }
})->with(['chat', 'ip', 'global', 'rollback']);

it('C5 abuse 7: the 11th new chat an hour per IP hash and the 301st overall are refused with no row and no cookie', function (string $limit) {
    if ($limit === 'ip') {
        foreach (range(1, 10) as $n) {
            expect(wcSend("hi {$n}")->status)->toBe(PostedMessage::ANSWERED);
        }

        $before = WebChat::query()->count();

        expect(wcSend('hi 11')->status)->toBe(PostedMessage::LIMITED)
            ->and(WebChat::query()->count())->toBe($before)
            ->and(wcIssuedToken())->toBeNull()
            ->and(wcSend('other ip', ip: '198.51.100.9')->status)->toBe(PostedMessage::ANSWERED);
    } else {
        foreach (range(1, 300) as $ignored) {
            RateLimiter::hit('web-chat:new:all', 3600);
        }

        expect(wcSend('hi', ip: '198.51.100.9')->status)->toBe(PostedMessage::LIMITED)
            ->and(WebChat::query()->count())->toBe(0)
            ->and(wcIssuedToken())->toBeNull();
    }
})->with(['ip', 'global']);

it('C5 abuse 8: press and topic with bad values, an older message index, an inactive or web-off rule are a no-op with HTTP 200', function (mixed $value) {
    [$chat, $token] = wcChat();
    WebChatMessage::factory()->create(['web_chat_id' => $chat->id, 'author' => ChatAuthor::Bot, 'text' => 'Old', 'buttons' => [['type' => 'handoff', 'label' => 'Manager'], ['type' => 'handoff', 'label' => 'Manager']]]);
    WebChatMessage::factory()->create(['web_chat_id' => $chat->id, 'author' => ChatAuthor::Bot, 'text' => 'New', 'buttons' => [['type' => 'menu', 'label' => 'Menu']]]);
    $before = WebChatMessage::query()->count();

    wgWidget($token)->call('press', $value)->call('topic', $value)->assertOk();

    expect(WebChatMessage::query()->count())->toBe($before)
        ->and($chat->fresh()->mode)->toBe(ChatMode::Bot);
})->with([
    'exponent string' => ['1e3'],
    'negative' => [-1],
    'out of range' => [99],
    'array' => [[]],
    'sql-ish' => ['0 OR 1'],
    'null' => [null],
    'float' => [1.0],
    'numeric string' => ['0'],
    'index of an older message' => [1],
]);

it('C5 abuse 8: a topic of an inactive, web-off or non-menu rule answers nothing and starts no chat', function (array $attributes) {
    $rule = cbRule('wab_topic', ['wabtopic'], array_merge(['in_menu' => true], $attributes));

    wgWidget()->call('topic', $rule->id)->assertOk();

    expect(WebChat::query()->count())->toBe(0)->and(WebChatMessage::query()->count())->toBe(0);
})->with([
    'inactive' => [['is_active' => false]],
    'web off' => [['on_web' => false]],
    'not in the menu' => [['in_menu' => false]],
]);

it('C5 abuse 9: a stale product button after the product is hidden says unavailable and no hidden id reaches a card or a lead item', function () {
    Event::fake([LeadCreated::class]);
    $product = wcProduct('WAB9001', ['price' => 100_000_00]);
    $widget = wgWidget();
    $widget->call('send', 'WAB9001')->assertSee('Scanner WAB9001');
    $product->update(['is_active' => false]);

    $html = wgWidget(wcIssuedToken() ?? app(WebChatToken::class)->current())->html();
    expect($html)->not->toContain('Scanner WAB9001')->not->toContain('data-chat-product>');

    $widget->call('press', 0)->assertSee(__('chatbot.search.unavailable'))->assertDontSee('data-chat-form', false);

    // A form opened before the product was hidden cannot carry it into a lead either.
    $chat = WebChat::query()->sole();
    $chat->forceFill(['dialog' => WebChatDialog::withForm(LeadFlow::Quote, $product->id)])->save();
    $this->travel(5)->seconds();
    wgWidget(app(WebChatToken::class)->current())->set('name', 'Ali')->set('phone', '90 123 45 67')->call('submitLead')->assertHasErrors('form');

    expect(Lead::query()->count())->toBe(0)->and(DB::table('lead_items')->where('product_id', $product->id)->count())->toBe(0);
});

it('C5 abuse 10: 61 web searches a minute limit the web only, Telegram search still works and web chat N never shares a budget with Telegram user N', function () {
    wcProduct('WAB10');
    config(['chatbot.search.per_minute_global_web' => 60]);

    foreach (range(1, 61) as $n) {
        [, $token] = wcChat();
        wcSend('WAB10', $token, ip: '198.51.100.'.$n);
    }

    $replies = WebChatMessage::query()->where('author', ChatAuthor::Bot)->orderBy('id')->pluck('text');

    expect($replies)->toHaveCount(61)
        ->and($replies->last())->toBe(__('chatbot.search.limited', [], 'en'))
        ->and($replies->take(60)->filter(fn (string $text): bool => $text === __('chatbot.search.limited', [], 'en')))->toHaveCount(0);

    $telegram = TelegramUser::factory()->registered()->create(['id' => 5005]);
    $web = WebChat::factory()->create(['id' => 5005]);
    $search = fn (TelegramUser|WebChat $subject): string => app(AnswerProductSearch::class)->handle(
        $subject,
        new ChatContext($subject instanceof WebChat ? ChatChannel::Web : ChatChannel::Telegram, 'en', 'WAB10'),
        new ChatReply('prompt', [], null, ChatReply::SEARCH),
    )->text;

    expect($search($telegram))->not->toBe(__('chatbot.search.limited', [], 'en'))
        ->and(RateLimiter::attempts('chat-search:user:5005'))->toBe(1)
        ->and(RateLimiter::attempts('chat-search:user:web:5005'))->toBe(0);

    $search($web);

    expect(RateLimiter::attempts('chat-search:user:web:5005'))->toBe(1)
        ->and(RateLimiter::attempts('chat-search:user:5005'))->toBe(1);
});

it('C5 abuse 11: unmatched phrases are stored redacted with channel web, never for banned or no-consent chats, and the web cap leaves Telegram alone', function () {
    wcSend('xyzzy +998 90 123 45 67 card 8600 1234 5678 9012 mail ali@example.com see https://evil.example/path?x=1');
    $row = ChatUnmatched::query()->sole();

    expect($row->channel)->toBe(ChatChannel::Web)
        ->and($row->sample)->not->toContain('123 45 67')->not->toContain('8600')->not->toContain('example.com')->not->toContain('evil.example')
        ->and($row->normalized)->not->toContain('8600')->not->toContain('example.com');

    ChatUnmatched::query()->delete();
    [, $banned] = wcChat([], fn ($factory) => $factory->banned());
    wcSend('xyzzy plugh', $banned);
    $noConsent = WebChat::factory()->create(['consent_at' => null]);
    $recorded = app(RecordUnmatched::class)->handle($noConsent, 'en', 'xyzzy plugh');

    expect(ChatUnmatched::query()->count())->toBe(0)->and($recorded)->toBeNull();

    $day = today()->format('Ymd');

    foreach (range(1, 300) as $ignored) {
        RateLimiter::hit("chatbot-unmatched:all:web:{$day}", 86400);
    }

    expect(app(RecordUnmatched::class)->handle(WebChat::factory()->create(), 'en', 'xyzzy plugh two'))->toBeNull()
        ->and(app(RecordUnmatched::class)->handle(TelegramUser::factory()->registered()->create(), 'en', 'xyzzy plugh two'))->not->toBeNull();
});

it('C5 abuse 12: every lead form guard holds and a flow is submitted at most once', function (string $guard) {
    Event::fake([LeadCreated::class]);
    $ipKey = 'site-lead:'.sha1((string) request()->ip());
    [$chat, $token] = wcChat(['dialog' => WebChatDialog::withForm(LeadFlow::Callback)]);
    $this->travel(5)->seconds();
    $submit = fn (string $phone = '90 123 45 67') => wgWidget($token)->set('name', 'Ali')->set('phone', $phone)->call('submitLead');

    match ($guard) {
        'honeypot' => wgWidget($token)->set('name', 'Ali')->set('phone', '90 123 45 67')->set('fax_ext', 'x')->call('submitLead')->assertSet('leadSent', true),
        'too fast' => (function () use ($chat, $token): void {
            $chat->forceFill(['dialog' => WebChatDialog::withForm(LeadFlow::Callback)])->save();
            wgWidget($token)->set('name', 'Ali')->set('phone', '90 123 45 67')->call('submitLead')->assertSet('leadSent', true);
        })(),
        'ip budget shared with LeadForm' => (function () use ($ipKey, $submit): void {
            foreach (range(1, LeadForm::RATE_LIMIT) as $ignored) {
                RateLimiter::hit($ipKey, LeadForm::RATE_LIMIT_SECONDS);
            }

            $submit()->assertHasErrors('form');
        })(),
        'phone budget' => (function () use ($submit): void {
            foreach (range(1, LeadForm::PHONE_LIMIT) as $ignored) {
                RateLimiter::hit('site-lead-phone:'.sha1('+998901234567'), LeadForm::PHONE_LIMIT_SECONDS);
            }

            $submit()->assertHasErrors('form');
        })(),
        'foreign phone cap' => (function () use ($submit): void {
            config(['site.foreign_phone_limit_per_hour' => 1]);
            RateLimiter::hit(ForeignPhoneLimit::KEY, ForeignPhoneLimit::DECAY_SECONDS);

            $submit('+7 916 000 40 02')->assertHasErrors('form');
        })(),
        'tampered flow and product' => (function (): void {
            $widget = wgWidget(app(WebChatToken::class)->current() ?? null);

            expect(fn () => $widget->set('flow', 'quote'))->toThrow(Exception::class, 'not found');
            expect(fn () => $widget->set('productId', 1))->toThrow(Exception::class, 'not found');
        })(),
        'double submit' => (function () use ($submit): void {
            $submit()->assertHasNoErrors();
            $submit()->assertSet('notice', 'unavailable');
        })(),
    };

    $leads = Lead::query()->count();

    expect($leads)->toBeLessThanOrEqual(1);

    if ($guard === 'double submit') {
        expect($leads)->toBe(1);
        Event::assertDispatchedTimes(LeadCreated::class, 1);
    } elseif ($guard === 'tampered flow and product') {
        expect($leads)->toBe(0);
    } else {
        expect($leads)->toBe(0);
        Event::assertNotDispatched(LeadCreated::class);
    }
})->with(['honeypot', 'too fast', 'ip budget shared with LeadForm', 'phone budget', 'foreign phone cap', 'tampered flow and product', 'double submit']);

it('C5 abuse 13: the service flow carries no product, whatever the stored dialog says', function () {
    Event::fake([LeadCreated::class]);
    $product = wcProduct('WAB13');
    [$chat, $token] = wcChat();
    DB::table('web_chats')->where('id', $chat->id)->update(['dialog' => json_encode(['form' => ['flow' => 'service', 'product_id' => $product->id, 'at' => now()->getTimestamp()]])]);
    $this->travel(5)->seconds();

    wgWidget($token)->set('name', 'Ali')->set('phone', '90 123 45 67')->set('details', 'Printer jams')->call('submitLead')->assertHasNoErrors();

    $lead = Lead::query()->sole();
    expect($lead->type)->toBe(LeadType::ServiceRequest)
        ->and($lead->type)->not->toBe(LeadType::Quote)
        ->and($lead->items()->whereNotNull('product_id')->count())->toBe(0);
    Event::assertDispatchedTimes(LeadCreated::class, 1);
});

it('C5 abuse 14: a visitor phone equal to a CRM customer sets no customer on the chat or the lead and assigns no manager', function () {
    Event::fake([LeadCreated::class]);
    $customer = Customer::factory()->create(['phone' => '+998901234567']);
    [$chat, $token] = wcChat(['dialog' => WebChatDialog::withForm(LeadFlow::Callback)]);
    $this->travel(5)->seconds();

    wgWidget($token)->set('name', 'Ali')->set('phone', '90 123 45 67')->call('submitLead')->assertHasNoErrors();

    $chat->refresh();
    $lead = Lead::query()->sole();

    expect($lead->customer_id)->toBeNull()
        ->and($lead->assigned_to ?? null)->toBeNull()
        ->and($chat->customer_id)->toBeNull()
        ->and($chat->manager_id)->toBeNull()
        ->and($customer->id)->not->toBeNull();

    // Staff convert of another chat with the same phone behaves the same.
    $other = wabHandedOff(['phone' => '+998901234567', 'name' => 'Ali']);
    $other->messages()->create(['author' => ChatAuthor::Visitor, 'text' => 'Need a quote']);
    Livewire::actingAs(staffUser())->test(Show::class, ['webChat' => $other])->call('openConvert')->call('convert');

    expect($other->fresh()->customer_id)->toBeNull()
        ->and($other->fresh()->lead?->customer_id)->toBeNull()
        ->and($other->fresh()->manager_id)->toBeNull();
});

it('C5 abuse 15: manager mode answers the 21st unanswered message with "please wait" and a same text within 3 seconds is stored once', function () {
    Event::fake([WebChatHandedOff::class]);
    config(['chatbot.web.per_minute_chat' => 100, 'chatbot.web.per_minute_ip' => 100]);
    [$chat, $token] = wcChat(['mode' => ChatMode::Manager, 'handoff_at' => now()->subMinute(), 'last_alert_at' => now()]);

    foreach (range(1, 20) as $n) {
        expect(wcSend("question {$n}", $token)->status)->toBe(PostedMessage::QUEUED);
    }

    expect(wcSend('question 21', $token)->status)->toBe(PostedMessage::WAIT)
        ->and($chat->messages()->where('author', ChatAuthor::Visitor)->count())->toBe(20)
        ->and($chat->messages()->where('text', 'question 21')->count())->toBe(0);

    [$second, $secondToken] = wcChat();
    wcSend('same text', $secondToken);

    expect(wcSend('same text', $secondToken)->status)->toBe(PostedMessage::IGNORED)
        ->and($second->messages()->where('author', ChatAuthor::Visitor)->count())->toBe(1);
});

it('C5 abuse 16: an idle refresh stays within its ceiling, the 31st a minute runs only the limiter, a staff reply renders and a rolled-back one does not bump the version', function () {
    $staff = staffUser();
    [$chat, $token] = wcChat(['mode' => ChatMode::Manager, 'handoff_at' => now()->subMinutes(5), 'consent_at' => now()->subMinutes(6)]);
    WebChatVersion::bump($chat->id);
    $widget = wgWidget($token);
    WebChatSwitch::isOpenCached();

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $widget->call('refresh')->assertReturned(false);
    $app = array_values(array_filter($queries, fn (string $sql): bool => preg_match('/[`"](cache|sessions)/', $sql) !== 1));

    expect($app)->toHaveCount(1)->and($app[0])->toMatch('/[`"]web_chats[`"]/');

    // A rolled-back staff reply never changes the version.
    $seen = WebChatVersion::get($chat->id);
    expect(fn () => DB::transaction(function () use ($chat, $staff): void {
        app(SendWebChatReply::class)->handle($chat->fresh(), $staff, 'Will be rolled back');

        throw new RuntimeException('rollback');
    }))->toThrow(RuntimeException::class);

    expect(WebChatVersion::get($chat->id))->toBe($seen);
    $widget->call('refresh')->assertReturned(false)->assertDontSee('Will be rolled back');

    // A committed one renders on the next refresh.
    app(SendWebChatReply::class)->handle($chat->fresh(), $staff, 'Your printer is ready');
    $widget->call('refresh')->assertReturned(true)->assertSee('Your printer is ready');

    // Over the budget: only the limiter runs, nothing renders.
    foreach (range(1, (int) config('chatbot.web.refresh_per_minute')) as $ignored) {
        $widget->call('refresh');
    }

    $queries = [];
    WebChatMessage::factory()->create(['web_chat_id' => $chat->id, 'author' => ChatAuthor::Staff, 'text' => 'Late reply']);
    WebChatVersion::bump($chat->id);
    $widget->call('refresh')->assertReturned(false)->assertDontSee('Late reply');

    expect(array_filter($queries, fn (string $sql): bool => preg_match('/[`"]web_chats[`"]/', $sql) === 1))->toBe([]);
});

it('C5 abuse 17: a chat idle for 25 hours starts fresh before any prune and a staff reply to a closed or expired chat is refused', function () {
    [$old, $token] = wcChat(['last_message_at' => now()->subHours(25)]);

    $posted = wcSend('Hello', $token);

    expect($posted->chatId)->not->toBe($old->id)
        ->and($old->messages()->count())->toBe(0)
        ->and(wcIssuedToken())->not->toBe($token);

    $admin = staffUser();

    foreach ([
        wabHandedOff(['conversation_status' => 'closed', 'closed_at' => now()]),
        wabHandedOff(['last_message_at' => now()->subHours(25)]),
    ] as $chat) {
        expect(fn () => app(SendWebChatReply::class)->handle($chat, $admin, 'Hello?'))->toThrow(InboxRefused::class)
            ->and($chat->messages()->where('author', ChatAuthor::Staff)->count())->toBe(0);

        Livewire::actingAs($admin)->test(Show::class, ['webChat' => $chat])->set('reply', 'Hello?')->call('sendReply');
        expect($chat->messages()->where('author', ChatAuthor::Staff)->count())->toBe(0);
    }
});

it('C5 abuse 18: a banned cookie is unavailable and keeps the cookie, and the same IP hash gets one new chat an hour for 24 hours', function () {
    [$banned, $token] = wcChat(['banned_at' => now(), 'ip_hash' => WebChatIp::hash('203.0.113.7')]);

    wgWidget($token)->assertSee(__('chatbot.widget.unavailable'))->call('send', 'hello');

    expect(WebChatMessage::query()->count())->toBe(0)
        ->and(WebChat::query()->count())->toBe(1)
        ->and(Cookie::queued(WebChatToken::cookieName()))->toBeNull()
        ->and(wcSend('hello again', $token)->status)->toBe(PostedMessage::UNAVAILABLE)
        ->and(WebChat::query()->count())->toBe(1);

    // The cookie is gone (deleted by the visitor): 1 chat an hour from that IP, other IPs unaffected.
    expect(wcSend('first')->status)->toBe(PostedMessage::ANSWERED)
        ->and(wcSend('second')->status)->toBe(PostedMessage::LIMITED)
        ->and(wcSend('other ip', ip: '198.51.100.20')->status)->toBe(PostedMessage::ANSWERED);

    $this->travel(25)->hours();

    expect(wcSend('much later')->status)->toBe(PostedMessage::ANSWERED)
        ->and($banned->fresh()->banned_at)->not->toBeNull();
});

it('C5 abuse 19: the inbox and every Show method are scoped per role and a tampered channel filter shows all', function () {
    $sales = staffUser(Role::SalesManager);
    $otherSales = User::factory()->withRole(Role::SalesManager)->create();
    $own = wabHandedOff(['name' => 'Ownperson'], $sales);
    $free = wabHandedOff(['name' => 'Freeperson']);
    $others = wabHandedOff(['name' => 'Otherperson'], $otherSales);
    $botOnly = WebChat::factory()->create(['name' => 'Botonlyperson']);
    TelegramUser::factory()->registered()->create(['name' => 'Tgperson']);

    // Sales: own + unassigned handed off; never another manager's or a bot-only chat, by URL and by Livewire.
    foreach ([$others, $botOnly] as $hidden) {
        $this->actingAs($sales)->get(route('admin.telegram.chats.web.show', $hidden))->assertForbidden();
        Livewire::actingAs($sales)->test(Show::class, ['webChat' => $hidden])->assertForbidden();
    }

    foreach ([$own, $free] as $visible) {
        $this->actingAs($sales)->get(route('admin.telegram.chats.web.show', $visible))->assertOk();
    }

    Livewire::actingAs($sales)->test(Index::class)->assertSee('Ownperson')->assertSee('Freeperson')->assertDontSee('Otherperson')->assertDontSee('Botonlyperson');
    Livewire::actingAs(staffUser(Role::Admin))->test(Index::class)->assertSee('Otherperson')->assertSee('Botonlyperson')->assertSee('Tgperson');
    Livewire::actingAs($sales)->test(Index::class)->set('channel', 'x" OR 1=1 --')->assertSee('Tgperson')->assertSee('Ownperson');

    foreach ([Role::ServiceEngineer, Role::ContentManager] as $role) {
        $this->actingAs(staffUser($role))->get(route('admin.telegram.chats'))->assertForbidden();
        $this->actingAs(staffUser($role))->get(route('admin.telegram.chats.web.show', $free))->assertForbidden();
        Livewire::actingAs(staffUser($role))->test(Show::class, ['webChat' => $free])->assertForbidden();
    }

    // Every Show method re-authorizes: after the chat moved to another manager each one is 403.
    foreach (['refreshThread', 'sendReply', 'take', 'assign', 'close', 'reopen', 'ban', 'unban', 'openConvert', 'convert'] as $method) {
        $chat = wabHandedOff([], $sales);
        $component = Livewire::actingAs($sales)->test(Show::class, ['webChat' => $chat])->assertOk();
        $chat->forceFill(['manager_id' => $otherSales->id])->save();

        $component->call($method)->assertForbidden();

        expect($chat->fresh()->messages()->where('author', ChatAuthor::Staff)->count())->toBe(0)
            ->and($chat->fresh()->isBanned())->toBeFalse()
            ->and($chat->fresh()->manager_id)->toBe($otherSales->id);
    }
});

it('C5 abuse 20: the UNION inbox paginates stably with equal timestamps, never lazy loads and searches W- with wildcards literally', function () {
    Model::preventLazyLoading();
    $admin = staffUser();
    $at = now()->startOfMinute();

    foreach (range(1, 15) as $ignored) {
        TelegramUser::factory()->registered()->create(['last_message_at' => $at]);
        wabHandedOff(['last_message_at' => $at]);
    }

    $first = Livewire::actingAs($admin)->test(Index::class)->viewData('items');
    $second = Livewire::actingAs($admin)->test(Index::class)->call('gotoPage', 2)->viewData('items');
    $keys = fn ($page): array => $page->getCollection()->map(fn (array $row): string => $row['channel'].$row['chat']->id)->all();

    expect($first->total())->toBe(30)
        ->and(count($keys($first)))->toBe(25)
        ->and(count($keys($second)))->toBe(5)
        ->and(array_intersect($keys($first), $keys($second)))->toBe([])
        ->and($first->getCollection()->every(fn (array $row): bool => $row['chat']->relationLoaded('manager')))->toBeTrue();

    WebChat::query()->delete();
    $target = wabHandedOff(['name' => 'Alisher', 'phone' => '+998901112233']);
    wabHandedOff(['name' => 'Bobur', 'phone' => '+998907778899']);
    $list = Livewire::actingAs($admin)->test(Index::class)->call('setChannel', 'web');

    $list->set('search', 'W-'.$target->id)->assertSee('Alisher')->assertDontSee('Bobur');
    $list->set('search', 'W-%')->assertDontSee('Alisher')->assertDontSee('Bobur');
    $list->set('search', '100%_')->assertDontSee('Alisher')->assertDontSee('Bobur');
    $list->set('search', 'W-_')->assertDontSee('Alisher')->assertDontSee('Bobur');
});

it('C5 abuse 21: the hand-off alert has no phone, name, text or page, links to the canonical https host and is throttled per chat and per hour', function () {
    Event::fake([WebChatHandedOff::class]);
    config(['app.url' => 'https://poson.uz', 'chatbot.web.alerts_per_hour' => 30]);
    app('request')->headers->set('host', 'evil.example');
    RateLimiter::clear(WebChatHandedOff::LIMIT_KEY);
    Cache::forget(WebChatHandedOff::LIMITED_KEY);

    $chat = WebChat::factory()->withContact('+998901234567', 'Alisher')->create(['first_page' => '/en/products/secret-page', 'locale' => 'ru']);
    $chat->messages()->create(['author' => ChatAuthor::Visitor, 'text' => 'my secret text']);
    $message = (new WebChatHandedOff($chat->id))->telegramMessage('en');

    expect($message->html)->toContain('W-'.$chat->id)
        ->not->toContain('901234567')->not->toContain('Alisher')->not->toContain('secret')->not->toContain('evil.example')
        ->not->toMatch('/\d{7,}/')
        ->and($message->buttons[0]['url'])->toBe('https://poson.uz/admin/chats/web/'.$chat->id);

    // Per chat: one alert per 10 minutes.
    $handoff = app(HandOffWebChat::class);
    $fresh = WebChat::factory()->create();
    $handoff->alert($fresh, WebChatHandedOff::REASON_MESSAGE);
    $handoff->alert($fresh, WebChatHandedOff::REASON_MESSAGE);
    Event::assertDispatchedTimes(WebChatHandedOff::class, 1);
    $this->travel(11)->minutes();
    $handoff->alert($fresh, WebChatHandedOff::REASON_MESSAGE);
    Event::assertDispatchedTimes(WebChatHandedOff::class, 2);

    // Globally: the 31st alert of the hour is one "limited" notice, the rest nothing.
    RateLimiter::clear(WebChatHandedOff::LIMIT_KEY);
    $messages = WebChat::factory()->count(33)->create()->map(fn (WebChat $each) => (new WebChatHandedOff($each->id))->telegramMessage('en'));
    $limited = e(__('telegram.web_chat.limited', [], 'en'));

    expect($messages->take(30)->filter()->count())->toBe(30)
        ->and($messages->filter(fn ($each) => $each !== null && str_contains($each->html, $limited)))->toHaveCount(1)
        ->and($messages[31])->toBeNull()
        ->and($messages[32])->toBeNull();
});

it('C5 abuse 22: web AI stays dark with everything on except chatbot.ai.web_ready', function () {
    config(['services.anthropic.key' => 'sk-ant-test-fake', 'chatbot.web_ready' => true]);

    // An Admin enabling web AI is refused while only the widget is ready.
    expect(fn () => app(SaveChatbotSettings::class)->handle(['ai_web_enabled' => true], staffUser(Role::Admin)))
        ->toThrow(ValidationException::class)
        ->and(AiSwitch::isStored(ChatChannel::Web))->toBeFalse();

    // Even with the stored row forced true, nothing counts as on.
    Setting::query()->updateOrCreate(['key' => AiSwitch::keyFor(ChatChannel::Web)], ['group' => SettingsArea::Chatbot->group(), 'value' => true]);
    ChatbotSwitch::forget();
    $model = Mockery::mock(ChatModel::class);
    $model->shouldNotReceive('complete');
    app()->instance(ChatModel::class, $model);

    expect(config('chatbot.ai.web_ready'))->toBeFalse()
        ->and(ChatbotSwitch::isEnabled(ChatChannel::Web))->toBeTrue()
        ->and(AiSwitch::isStored(ChatChannel::Web))->toBeTrue()
        ->and(AiSwitch::isEnabled(ChatChannel::Web))->toBeFalse();

    config(['chatbot.web.per_minute_chat' => 100]);
    wcSend('xyzzy plugh');
    $token = (string) wcIssuedToken();
    wcSend('what is the meaning of everything', $token);
    wcSend('ZD421', $token);

    expect(ChatAiTurn::query()->count())->toBe(0)
        ->and(WebChatMessage::query()->where('author', ChatAuthor::Bot)->whereNotNull('chat_ai_turn_id')->count())->toBe(0)
        ->and(WebChat::query()->sole()->dialog)->toBeNull();

    Http::assertNothingSent();
});

it('C5 abuse 23: first_page ignores a forged Referer, drops the query string and order or quote links, and Show prints it as plain text', function (?string $page, ?string $stored) {
    app('request')->headers->set('referer', 'https://evil.example/phish?x=1');

    wcSend('Hello', page: $page);

    expect(WebChat::query()->sole()->first_page)->toBe($stored);
})->with([
    'query string dropped' => ['/ru/products/x?utm=1', '/ru/products/x'],
    'order page never stored' => ['/o/01JABCDEF', null],
    'localized order page' => ['/ru/o/01JABCDEF', null],
    'quote link never stored' => ['/q/01JABCDEF', null],
    'localized quote link' => ['/uz/q/01JABCDEF', null],
    'livewire update route' => ['/livewire-4aeeedbb/update', null],
    'no page' => [null, null],
]);

it('C5 abuse 23: a stored first_page is rendered as plain text, never as a link', function () {
    $chat = wabHandedOff(['first_page' => '/en/product/<b>x</b>']);

    Livewire::actingAs(staffUser())->test(Show::class, ['webChat' => $chat])
        ->assertSee('/en/product/&lt;b&gt;x&lt;/b&gt;', false)
        ->assertDontSee('<b>x</b>', false)
        ->assertDontSee('href="/en/product', false);
});

it('C5 abuse 24: every retention class is pruned at its age, banned chats lose all text at 31 days, ip_hash goes at 30 days and logs are counts only', function () {
    $expiring = wabPruneChat('25 hours', ['mode' => ChatMode::Manager, 'handoff_at' => now()->subHours(25)]);
    $fresh = wabPruneChat('2 hours');
    $bot = [wabPruneChat('29 days'), wabPruneChat('31 days')];
    $handed = [wabPruneChat('89 days', ['handoff_at' => now()->subDays(100)]), wabPruneChat('91 days', ['handoff_at' => now()->subDays(100)])];
    $withLead = fn () => ['lead_id' => Lead::factory()->create()->id, 'handoff_at' => now()->subDays(100)];
    $led = [wabPruneChat('11 months', $withLead()), wabPruneChat('13 months', $withLead())];
    $ip = [wabPruneChat('29 days', ['ip_hash' => str_repeat('a', 64), 'handoff_at' => now()]), wabPruneChat('31 days', ['ip_hash' => str_repeat('b', 64), 'handoff_at' => now()])];
    $banned = wabPruneChat('40 days', ['banned_at' => now()->subDays(31), 'name' => 'Bad', 'phone' => '+998901234567', 'first_page' => '/en/x', 'dialog' => ['a' => 1]]);
    $recentBan = wabPruneChat('2 days', ['banned_at' => now()->subDays(29), 'name' => 'Bad', 'phone' => '+998901234567']);
    $oldBan = wabPruneChat('13 months', ['banned_at' => now()->subMonths(13)]);
    $turn = ChatAiTurn::factory()->create(['channel' => ChatChannel::Web, 'telegram_user_id' => null, 'web_chat_id' => $bot[1]->id]);

    Log::spy();
    $this->artisan('chat:prune')->assertSuccessful();

    expect($expiring->fresh()->isClosed())->toBeTrue()
        ->and($expiring->fresh()->messages()->count())->toBe(1)
        ->and($fresh->fresh()->isClosed())->toBeFalse()
        ->and(WebChat::query()->whereKey($bot[0]->id)->exists())->toBeTrue()
        ->and(WebChat::query()->whereKey($bot[1]->id)->exists())->toBeFalse()
        ->and(WebChat::query()->whereKey($handed[0]->id)->exists())->toBeTrue()
        ->and(WebChat::query()->whereKey($handed[1]->id)->exists())->toBeFalse()
        ->and(WebChat::query()->whereKey($led[0]->id)->exists())->toBeTrue()
        ->and(WebChat::query()->whereKey($led[1]->id)->exists())->toBeFalse()
        ->and($ip[0]->fresh()->ip_hash)->not->toBeNull()
        ->and($ip[1]->fresh()->ip_hash)->toBeNull()
        ->and(ChatAiTurn::query()->whereKey($turn->id)->exists())->toBeFalse()
        ->and(WebChatMessage::query()->where('web_chat_id', $bot[1]->id)->count())->toBe(0)
        ->and(WebChat::query()->whereKey($oldBan->id)->exists())->toBeFalse()
        ->and($recentBan->fresh()->messages()->count())->toBe(1)
        ->and($recentBan->fresh()->phone)->not->toBeNull();

    $banned->refresh();
    expect($banned->banned_at)->not->toBeNull()
        ->and($banned->messages()->count())->toBe(0)
        ->and([$banned->name, $banned->phone, $banned->first_page, $banned->dialog])->each->toBeNull();

    Log::shouldHaveReceived('info')->with('Website chats pruned', Mockery::on(fn ($context): bool => array_keys($context) === ['expired', 'deleted', 'ip_cleared', 'purged']))->atLeast()->once();
    expect(app(PruneWebChats::class)->handle())->toBe(['expired' => 0, 'deleted' => 0, 'ip_cleared' => 0, 'purged' => 0]);

    // The same ages measured by the clock: a fresh chat expires after 25 hours and is deleted after 31 days.
    $clock = WebChat::factory()->create(['last_message_at' => now(), 'created_at' => now()]);
    $this->travel(25)->hours();
    app(PruneWebChats::class)->handle();
    expect($clock->fresh()->isClosed())->toBeTrue();
    $this->travel(31)->days();
    app(PruneWebChats::class)->handle();
    expect(WebChat::query()->whereKey($clock->id)->exists())->toBeFalse();
});

it('C5 abuse 25: with the web switch off an open widget stores nothing and says unavailable, and the launcher is absent on checkout, /o, /q, admin and in print', function () {
    [$chat, $token] = wcChat();
    $widget = wgWidget($token);
    Setting::query()->where('key', ChatbotSwitch::WEB_KEY)->firstOrFail()->update(['value' => false]);
    ChatbotSwitch::forget();
    $before = WebChatMessage::query()->count();

    $widget->call('send', 'hello')->assertReturned(false)->assertSee(__('chatbot.widget.unavailable'));
    $widget->call('press', 0)->call('topic', 1)->call('menu')->call('handoff');
    $widget->call('refresh')->assertReturned(true)->assertSet('mode', 'bot');

    expect(WebChatMessage::query()->count())->toBe($before)
        ->and($chat->fresh()->mode)->toBe(ChatMode::Bot)
        ->and(wcSend('hello', $token)->status)->toBe(PostedMessage::UNAVAILABLE)
        ->and(WebChat::query()->count())->toBe(1);

    // Switched back on: the launcher is hidden where it must be and carries print:hidden elsewhere.
    Setting::query()->updateOrCreate(['key' => ChatbotSwitch::WEB_KEY], ['group' => SettingsArea::Chatbot->group(), 'value' => true]);
    ChatbotSwitch::forget();
    WebChatSwitch::forget();
    config(['site.cart_enabled' => true]);

    $this->get('/en')->assertOk()->assertSee('data-chat-launcher', false)->assertSee('print:hidden', false);
    $this->get('/en/checkout')->assertOk()->assertDontSee('data-chat-launcher', false);

    $order = Order::factory()->totalling(100_00)->create();
    $this->get(route('orders.public', $order->ulid))->assertOk()->assertDontSee('data-chat-launcher', false);

    $quote = Quote::factory()->sent()->totalling(100_00)->create();
    $this->get(route('quotes.public', $quote->ulid))->assertOk()->assertDontSee('data-chat-launcher', false);

    $this->actingAs(staffUser())->get(route('admin.dashboard'))->assertOk()->assertDontSee('data-chat-launcher', false);
});

it('C5 abuse 26: the inbox renders a page of mixed telegram and web chats without a single lazy load', function () {
    $violations = [];
    Model::preventLazyLoading();
    Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) use (&$violations): void {
        $violations[] = $model::class.'::'.$relation;
    });
    $manager = User::factory()->withRole(Role::SalesManager)->create();

    foreach (range(1, 6) as $ignored) {
        TelegramUser::factory()->registered()->create(['manager_id' => $manager->id]);
        wabHandedOff([], $manager);
        wabHandedOff();
    }

    foreach (['all', 'telegram', 'web'] as $channel) {
        Livewire::actingAs(staffUser())->test(Index::class)->call('setChannel', $channel)->assertOk();
        Livewire::actingAs($manager)->test(Index::class)->call('setChannel', $channel)->assertOk();
    }

    expect($violations)->toBe([]);
});

it('C5 abuse 27: the raw chat token never reaches a log entry, the widget HTML, its snapshot or the page', function () {
    $logged = [];
    Log::listen(function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });
    Event::fake([WebChatHandedOff::class]);

    wcSend('Hello');
    $token = (string) wcIssuedToken();
    wcSend('xyzzy plugh', $token);
    WebChatMessage::factory()->create(['web_chat_id' => WebChat::query()->sole()->id, 'author' => ChatAuthor::Bot, 'text' => 'Pick', 'buttons' => [['type' => 'menu', 'label' => 'Menu']]]);

    $widget = wgWidget($token);
    $widget->call('press', 0)->call('refresh')->call('send', 'Another question')->call('handoff');
    $widget->call('refresh');
    $html = $widget->html();
    $snapshot = json_encode($widget->snapshot);
    $page = $this->withUnencryptedCookie(WebChatToken::cookieName(), wabCookie($token))->get('/en')->assertOk()->getContent();

    foreach ($logged as $entry) {
        expect($entry)->not->toContain($token)->not->toContain(WebChatToken::hash($token));
    }

    expect($html)->not->toContain($token)
        ->and($snapshot)->not->toContain($token)->not->toContain(WebChatToken::hash($token))
        ->and($page)->not->toContain($token)
        ->and(WebChat::query()->where('token_hash', $token)->exists())->toBeFalse();
});

it('C5 abuse 28: a staff reply whose transaction rolls back writes no message row and leaves the version unchanged', function () {
    $staff = staffUser();
    $chat = wabHandedOff();
    WebChatVersion::bump($chat->id);
    $seen = WebChatVersion::get($chat->id);
    $unread = $chat->unread_count;
    WebChatMessage::created(function (WebChatMessage $message): void {
        if ($message->author === ChatAuthor::Staff) {
            throw new RuntimeException('after the insert');
        }
    });

    expect(fn () => app(SendWebChatReply::class)->handle($chat->fresh(), $staff, 'Rolled back reply'))->toThrow(RuntimeException::class);

    $chat->refresh();
    expect($chat->messages()->where('author', ChatAuthor::Staff)->count())->toBe(0)
        ->and(WebChatMessage::query()->where('text', 'Rolled back reply')->exists())->toBeFalse()
        ->and(WebChatVersion::get($chat->id))->toBe($seen)
        ->and($chat->last_staff_reply_at)->toBeNull()
        ->and($chat->unread_count)->toBe($unread);
});

it('C5 abuse 29: a tampered lazy mount page is stored as a cleaned path only and never rendered raw', function (string $page, ?string $stored) {
    app('request')->headers->set('referer', 'https://evil.example/phish');

    $widget = wgWidget(page: $page);
    $widget->call('send', 'Hello');
    $chat = WebChat::query()->sole();

    expect($chat->first_page)->toBe($stored)
        ->and($widget->html())->not->toContain('javascript:alert')->not->toContain('<script');

    if ($stored !== null) {
        expect($stored)->toStartWith('/')->and(strlen($stored))->toBeLessThanOrEqual(255);
    }

    $chat->forceFill(['mode' => ChatMode::Manager, 'handoff_at' => now()])->save();
    $show = Livewire::actingAs(staffUser())->test(Show::class, ['webChat' => $chat])->html();

    expect($show)->not->toContain('href="'.$page)->not->toContain('<script');
})->with([
    'parent segments' => ['../../admin', '/'],
    'livewire update route' => ['/livewire/update', null],
    'order page' => ['/o/123', null],
    'javascript scheme' => ['javascript:alert(1)', '/alert(1)'],
    'script tag' => ['/en/<script>alert(1)</script>', '/en/<script>alert(1)</script>'],
    '5 KB string' => ['/'.str_repeat('a', 5000), '/'.str_repeat('a', 254)],
]);
