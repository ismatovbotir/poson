<?php

require_once __DIR__.'/WidgetHelpers.php';

use App\Actions\Chat\Web\PressWebChatButton;
use App\Enums\ChatAction;
use App\Enums\ChatAuthor;
use App\Enums\ChatMode;
use App\Enums\LeadFlow;
use App\Enums\LeadType;
use App\Events\Chat\WebChatHandedOff;
use App\Events\LeadCreated;
use App\Livewire\Site\Chat\Widget;
use App\Livewire\Site\LeadForm;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\User;
use App\Models\WebChat;
use App\Models\WebChatMessage;
use App\Support\Chat\ChatbotSwitch;
use App\Support\Chat\WebChatDialog;
use App\Support\Chat\WebChatSwitch;
use App\Support\Chat\WebChatToken;
use App\Support\Chat\WebChatVersion;
use App\Support\Money;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;

/**
 * Plan 28 C5b: Site\Chat\Widget (§19.3, CB-5, CB-18–CB-20, CB-24,
 * WC-6–WC-8, WC-10–WC-12, WC-16, WC-18). The 25 abuse cases of §20 stay
 * with test-writer (tests/Feature/Security/WebChatAbuseTest.php).
 */
beforeEach(fn () => wcSetup());

/**
 * A chat handed off to staff, with a staff reply.
 *
 * @return array{0: WebChat, 1: string}
 */
function wgManagerChat(): array
{
    [$chat, $token] = wcChat([
        'mode' => ChatMode::Manager,
        'handoff_at' => now()->subMinutes(5),
        'last_message_at' => now()->subMinutes(5),
        'consent_at' => now()->subMinutes(6),
    ]);

    return [$chat, $token];
}

it('mount_and_lazy_load_write_nothing_and_show_consent_menu_and_privacy_link', function () {
    Cookie::flushQueuedCookies();

    $widget = wgWidget();

    expect(WebChat::query()->count())->toBe(0)
        ->and(WebChatMessage::query()->count())->toBe(0)
        ->and(wcIssuedToken())->toBeNull()
        ->and($widget->get('locale'))->toBe('en')
        ->and($widget->get('page'))->toBe('/en/product/zebra-zd421');

    $widget->assertSee(__('chatbot.widget.consent'))
        ->assertSee(__('chatbot.widget.consent_link'))
        ->assertSee('/en/page/privacy', false)
        ->assertSee('data-chat-topics', false)
        ->assertDontSee('wire:poll', false);
});

it('has no chat id or token property and no method that takes an id', function () {
    $state = array_keys(wgWidget()->instance()->all());

    expect($state)->toEqualCanonicalizing(['locale', 'page', 'mode', 'seenVersion', 'pending', 'notice', 'leadSent', 'contactSaved', 'name', 'phone', 'details', 'fax_ext', 'contactName', 'contactPhone']);

    foreach ((new ReflectionClass(Widget::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== Widget::class) {
            continue;
        }

        foreach ($method->getParameters() as $parameter) {
            expect(strtolower($parameter->getName()))->not->toContain('chat')->not->toContain('token');
        }
    }

    expect((new ReflectionMethod(Widget::class, 'refresh'))->getNumberOfParameters())->toBe(0);
});

it('locks the state alpine reads', function (string $property, mixed $value) {
    wgWidget()->set($property, $value);
})->with([
    'mode' => ['mode', 'manager'],
    'seenVersion' => ['seenVersion', 'x'],
    'pending' => ['pending', true],
    'locale' => ['locale', 'ru'],
    'page' => ['page', '/o/abc'],
])->throws(CannotUpdateLockedPropertyException::class);

it('first_send_records_consent_first_page_and_cookie_then_answers_synchronously', function () {
    cbRule('wg_delivery', ['wgdeliveryx'], ['answer' => ['uz' => 'Yetkazish', 'ru' => 'Доставка', 'en' => 'We deliver in two days.']]);

    $widget = wgWidget(page: '/ru/product/zebra-zd421?utm=1');
    $widget->call('send', 'wgdeliveryx')->assertReturned(true);
    $chat = WebChat::query()->sole();

    expect($chat->consent_at)->not->toBeNull()
        ->and($chat->consent_version)->toBe(config('chatbot.web.consent_version'))
        ->and($chat->first_page)->toBe('/ru/product/zebra-zd421')
        ->and(wcIssuedToken())->not->toBeNull()
        ->and($widget->get('mode'))->toBe('bot')
        ->and($widget->get('seenVersion'))->toBe(WebChatVersion::get($chat->id));

    $widget->assertSee('We deliver in two days.')
        ->assertSee(__('chatbot.widget.bot'))
        ->assertDispatched('chat-activity')
        ->assertDontSee('data-chat-topics', false);
});

it('ignores non-string input and shows the limited notice over the per-chat budget', function () {
    $widget = wgWidget();
    $widget->call('send', ['x'])->assertReturned(false);

    expect(WebChat::query()->count())->toBe(0);

    config(['chatbot.web.per_minute_chat' => 1]);
    $widget->call('send', 'hello')->call('send', 'hello again')->assertSet('notice', 'too_many')
        ->assertSee(__('chatbot.widget.too_many'));

    expect(WebChatMessage::query()->where('author', ChatAuthor::Visitor)->count())->toBe(1);
});

it('a menu topic before the first send starts the chat with consent; a non-menu rule is a no-op', function () {
    $topic = cbRule('wg_warranty', ['warranty'], ['in_menu' => true, 'answer' => ['uz' => 'Kafolat', 'ru' => 'Гарантия', 'en' => 'Official warranty.']]);
    $hidden = cbRule('wg_secret', ['secret'], ['in_menu' => false]);

    $widget = wgWidget();
    $widget->call('topic', $hidden->id);

    expect(WebChat::query()->count())->toBe(0);

    $widget->call('topic', $topic->id)->assertSee('Official warranty.');
    $chat = WebChat::query()->sole();

    expect($chat->consent_at)->not->toBeNull()
        ->and($chat->messages()->where('author', ChatAuthor::Visitor)->value('text'))->not->toBeEmpty();
});

it('WC7_press_and_topic_tampering_is_a_no_op', function (mixed $value) {
    [$chat, $token] = wcChat();
    WebChatMessage::factory()->create(['web_chat_id' => $chat->id, 'author' => ChatAuthor::Bot, 'text' => 'Pick', 'buttons' => [['type' => 'menu', 'label' => 'Menu']]]);
    $before = WebChatMessage::query()->count();

    wgWidget($token)->call('press', $value)->call('topic', $value)->assertOk();

    expect(WebChatMessage::query()->count())->toBe($before);
})->with([
    'exponent string' => ['1e3'],
    'negative' => [-1],
    'out of range' => [99],
    'array' => [[]],
    'sql-ish' => ['0 OR 1'],
    'null' => [null],
    'float' => [0.0],
    'numeric string' => ['0'],
]);

it('WC7_an_index_of_an_older_bot_message_is_a_no_op', function () {
    [$chat, $token] = wcChat();
    WebChatMessage::factory()->create(['web_chat_id' => $chat->id, 'author' => ChatAuthor::Bot, 'text' => 'Old', 'buttons' => [['type' => 'handoff', 'label' => 'Manager'], ['type' => 'handoff', 'label' => 'Manager']]]);
    WebChatMessage::factory()->create(['web_chat_id' => $chat->id, 'author' => ChatAuthor::Bot, 'text' => 'New', 'buttons' => [['type' => 'menu', 'label' => 'Menu']]]);

    wgWidget($token)->call('press', 1);

    expect($chat->fresh()->mode)->toBe(ChatMode::Bot)
        ->and(WebChatMessage::query()->count())->toBe(2);
});

it('WC7_never_outputs_a_stored_url_that_is_no_longer_allowed', function () {
    [$chat, $token] = wcChat();
    WebChatMessage::factory()->create(['web_chat_id' => $chat->id, 'author' => ChatAuthor::Bot, 'text' => 'Links', 'buttons' => [
        ['type' => 'url', 'label' => 'Evil', 'url' => 'javascript:alert(1)'],
        ['type' => 'url', 'label' => 'Other host', 'url' => 'https://evil.example/x'],
        ['type' => 'url', 'label' => 'Site page', 'url' => rtrim((string) config('app.url'), '/').'/en/catalog'],
    ]]);

    wgWidget($token)
        ->assertDontSee('javascript:alert', false)
        ->assertDontSee('evil.example', false)
        ->assertDontSee('Other host')
        ->assertSee(rtrim((string) config('app.url'), '/').'/en/catalog', false);
});

it('hidden_product_card_vanishes_and_shows_retail_only', function () {
    $shown = wcProduct('QX1001', ['price' => 1_234_500_00, 'price_dealer' => 999_900_00, 'price_wholesale' => 888_800_00]);
    $onRequest = wcProduct('QX1002', ['price' => null]);
    $hidden = wcProduct('QX1003', ['price' => 555_500_00]);

    $widget = wgWidget();
    $widget->call('send', 'QX100');
    $hidden->update(['is_active' => false]);

    $html = wgWidget(wcIssuedToken() ?? app(WebChatToken::class)->current())->html();

    expect($html)->toContain('Scanner QX1001')
        ->toContain(Money::format(1_234_500_00))
        ->toContain('Scanner QX1002')
        ->toContain(__('chatbot.widget.price_on_request'))
        ->toContain(route('site.product', ['locale' => 'en', 'product' => $shown->slug]))
        ->not->toContain('Scanner QX1003')
        ->not->toContain(Money::format(999_900_00))
        ->not->toContain(Money::format(888_800_00))
        ->and(substr_count($html, 'data-chat-product>'))->toBe(2);

    expect(WebChatMessage::query()->where('author', ChatAuthor::Bot)->value('text'))->not->toContain('1 234');
});

it('WC16_cards_show_the_current_retail_price_after_a_price_change', function () {
    $product = wcProduct('QX2001', ['price' => 100_000_00]);
    wgWidget()->call('send', 'QX2001');
    $product->update(['price' => 222_000_00]);

    expect(wgWidget(app(WebChatToken::class)->current())->html())
        ->toContain(Money::format(222_000_00))
        ->not->toContain(Money::format(100_000_00));
});

it('a product card opens the quote form; a product hidden since then is unavailable', function () {
    $product = wcProduct('QX3001', ['price' => 100_000_00]);
    $widget = wgWidget();
    $widget->call('send', 'QX3001')->call('press', 0)->assertSee('data-chat-form="quote"', false)->assertSee('Scanner QX3001');

    expect(WebChatDialog::form(WebChat::query()->sole()->dialog))->toMatchArray(['flow' => LeadFlow::Quote, 'product_id' => $product->id]);

    $widget->call('menu');
    $this->travel(5)->seconds();
    $widget->call('send', 'QX3001');
    $product->update(['is_active' => false]);
    $widget->call('press', 0)->assertSee(__('chatbot.search.unavailable'))->assertDontSee('data-chat-form', false);
});

it('submits the lead form once: the guards run in LeadForm order and the lead is created once', function () {
    Event::fake([LeadCreated::class]);
    cbRule('wg_callme', ['callme'], ['action' => ChatAction::LeadDialog, 'params' => ['flow' => 'callback']]);
    $ipKey = 'site-lead:'.sha1((string) request()->ip());

    $widget = wgWidget();
    $widget->call('send', 'callme')->assertSee('data-chat-form="callback"', false);

    // 1. validation first: no limiter hit.
    $widget->set('name', 'Ali')->set('phone', '12')->call('submitLead')->assertHasErrors('phone');
    expect(RateLimiter::attempts($ipKey))->toBe(0);

    // 2. too fast (bot check after the limits): the success screen, no lead, the hit counted.
    $widget->set('phone', '90 123 45 67')->call('submitLead')->assertSet('leadSent', true);
    expect(Lead::query()->count())->toBe(0)->and(RateLimiter::attempts($ipKey))->toBe(1);

    // 3. a person: one lead.
    $this->travel(5)->seconds();
    $widget->set('name', 'Ali')->set('phone', '90 123 45 67')->call('submitLead')->assertHasNoErrors();
    $widget2 = wgWidget(app(WebChatToken::class)->current());
    $widget2->set('name', 'Ali')->set('phone', '90 123 45 67')->call('submitLead')->assertSet('notice', 'unavailable');

    $lead = Lead::query()->sole();
    expect($lead->type)->toBe(LeadType::Callback)
        ->and($lead->phone)->toBe('+998901234567')
        ->and($lead->customer_id)->toBeNull()
        ->and(WebChat::query()->sole()->lead_id)->toBe($lead->id);

    Event::assertDispatchedTimes(LeadCreated::class, 1);
});

it('shares the IP budget with Site\LeadForm: the 6th lead per IP is refused', function () {
    Event::fake([LeadCreated::class]);
    $ipKey = 'site-lead:'.sha1((string) request()->ip());

    foreach (range(1, LeadForm::RATE_LIMIT) as $ignored) {
        RateLimiter::hit($ipKey, LeadForm::RATE_LIMIT_SECONDS);
    }

    [$chat, $token] = wcChat(['dialog' => WebChatDialog::withForm(LeadFlow::Callback)]);
    $this->travel(5)->seconds();

    wgWidget($token)->set('name', 'Ali')->set('phone', '90 123 45 67')->call('submitLead')->assertHasErrors('form');

    expect(Lead::query()->count())->toBe(0);
});

it('honeypot: the success screen and no lead', function () {
    Event::fake([LeadCreated::class]);
    [$chat, $token] = wcChat(['dialog' => WebChatDialog::withForm(LeadFlow::Callback)]);
    $this->travel(5)->seconds();

    wgWidget($token)->set('name', 'Ali')->set('phone', '90 123 45 67')->set('fax_ext', 'x')->call('submitLead')
        ->assertSet('leadSent', true)->assertSee(__('chatbot.widget.lead_sent'));

    expect(Lead::query()->count())->toBe(0);
});

it('service flow never carries a product: a service_request lead', function () {
    Event::fake([LeadCreated::class]);
    $product = wcProduct('QX4001');
    [$chat, $token] = wcChat();
    // A forged dialog with a product on the service flow.
    DB::table('web_chats')->where('id', $chat->id)->update(['dialog' => json_encode(['form' => ['flow' => 'service', 'product_id' => $product->id, 'at' => now()->getTimestamp()]])]);
    $this->travel(5)->seconds();

    wgWidget($token)->set('name', 'Ali')->set('phone', '90 123 45 67')->set('details', 'Printer jams')->call('submitLead')->assertHasNoErrors();

    $lead = Lead::query()->sole();
    expect($lead->type)->toBe(LeadType::ServiceRequest)
        ->and($lead->items()->whereNotNull('product_id')->count())->toBe(0);
    Event::assertDispatchedTimes(LeadCreated::class, 1);
});

it('refuses the quote form when its product left the site', function () {
    Event::fake([LeadCreated::class]);
    $product = wcProduct('QX5001');
    [$chat, $token] = wcChat(['dialog' => WebChatDialog::withForm(LeadFlow::Quote, $product->id)]);
    $product->update(['is_active' => false]);
    $this->travel(5)->seconds();

    wgWidget($token)->set('name', 'Ali')->set('phone', '90 123 45 67')->call('submitLead')->assertHasErrors('form');

    expect(Lead::query()->count())->toBe(0);
});

it('manager mode: the role label "Manager", never a staff name, and the poll is on', function () {
    $staff = User::factory()->create(['name' => 'Sardor Staffname', 'email' => 'sardor@poson.test']);
    [$chat, $token] = wgManagerChat();
    WebChatMessage::factory()->create(['web_chat_id' => $chat->id, 'author' => ChatAuthor::Staff, 'user_id' => $staff->id, 'text' => 'Hello from the team']);

    $widget = wgWidget($token)->assertSet('mode', 'manager')->assertSee('Hello from the team')->assertSee(__('chatbot.widget.staff'));

    expect($widget->html())->not->toContain('Sardor')->not->toContain('sardor@poson.test')->toContain('data-mode="manager"');
});

it('refresh_skips_the_render_when_the_version_is_unchanged_within_the_query_ceiling', function () {
    [$chat, $token] = wgManagerChat();
    WebChatVersion::bump($chat->id);
    $widget = wgWidget($token);
    WebChatSwitch::isOpenCached();

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $widget->call('refresh')->assertReturned(false);

    $appQueries = array_values(array_filter($queries, fn (string $sql): bool => ! str_contains($sql, '"cache') && ! str_contains($sql, '"sessions"')));
    expect($appQueries)->toHaveCount(1)
        ->and($appQueries[0])->toContain('"web_chats"');
});

it('refresh_renders_after_a_staff_reply_bumps_the_version', function () {
    [$chat, $token] = wgManagerChat();
    WebChatVersion::bump($chat->id);
    $widget = wgWidget($token);

    WebChatMessage::factory()->create(['web_chat_id' => $chat->id, 'author' => ChatAuthor::Staff, 'text' => 'Your printer is ready']);
    WebChatVersion::bump($chat->id);

    $widget->call('refresh')->assertReturned(true)->assertSee('Your printer is ready')->assertDispatched('chat-updated');
});

it('refresh_has_its_own_budget_the_31st_per_minute_runs_no_query', function () {
    [$chat, $token] = wgManagerChat();
    WebChatVersion::bump($chat->id);
    $widget = wgWidget($token);
    WebChatSwitch::isOpenCached();

    foreach (range(1, (int) config('chatbot.web.refresh_per_minute')) as $ignored) {
        $widget->call('refresh');
    }

    $queries = 0;
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries += str_contains($query->sql, '"web_chats"') ? 1 : 0;
    });

    WebChatMessage::factory()->create(['web_chat_id' => $chat->id, 'author' => ChatAuthor::Staff, 'text' => 'Late reply']);
    WebChatVersion::bump($chat->id);
    $widget->call('refresh')->assertReturned(false)->assertDontSee('Late reply');

    expect($queries)->toBe(0);
});

it('kill switch off mid-session: send stores nothing and the widget says unavailable', function () {
    [$chat, $token] = wcChat();
    $widget = wgWidget($token);
    Setting::query()->where('key', ChatbotSwitch::WEB_KEY)->update(['value' => false]);
    ChatbotSwitch::forget();

    $widget->call('send', 'hello')->assertReturned(false)->assertSee(__('chatbot.widget.unavailable'));
    $widget->call('refresh')->assertReturned(true)->assertSet('mode', 'bot');

    expect(WebChatMessage::query()->count())->toBe(0);
});

it('escapes visitor, bot and staff text', function () {
    [$chat, $token] = wgManagerChat();
    foreach ([ChatAuthor::Visitor, ChatAuthor::Bot, ChatAuthor::Staff] as $author) {
        WebChatMessage::factory()->create(['web_chat_id' => $chat->id, 'author' => $author, 'text' => '<img src=x onerror=alert(1)>', 'buttons' => $author === ChatAuthor::Bot ? [['type' => 'menu', 'label' => '<b>Menu</b>']] : null]);
    }

    $html = wgWidget($token)->html();

    expect($html)->not->toContain('<img src=x')
        ->not->toContain('<b>Menu</b>')
        ->and(substr_count($html, '&lt;img src=x onerror=alert(1)&gt;'))->toBe(3);
});

it('hands off from the menu chip, offers the contact card once and saves the phone without matching a customer', function () {
    Event::fake([WebChatHandedOff::class]);
    $widget = wgWidget();
    $widget->call('handoff')->assertSet('mode', 'manager')->assertSee('data-chat-contact>', false);
    $chat = WebChat::query()->sole();

    expect($chat->handoff_at)->not->toBeNull();

    $widget->set('contactName', "Ali\u{202E} Valiyev")->set('contactPhone', '90 123 45 67')->call('saveContact')
        ->assertHasNoErrors()->assertSet('contactSaved', true)->assertDontSee('data-chat-contact>', false);

    $chat->refresh();
    expect($chat->phone)->toBe('+998901234567')
        ->and($chat->name)->toBe('Ali Valiyev')
        ->and($chat->customer_id)->toBeNull()
        ->and($chat->messages()->pluck('text')->implode(' '))->not->toContain('901234567');

    // Only once.
    $widget->set('contactPhone', '90 765 43 21')->call('saveContact');
    expect($chat->fresh()->phone)->toBe('+998901234567');
});

it('after hours the hand-off adds the after-hours text and a callback button that opens the form', function () {
    Event::fake([WebChatHandedOff::class]);
    $this->travelTo(now('Asia/Tashkent')->next('Sunday')->setTime(23, 30));

    $widget = wgWidget();
    $widget->call('handoff')->assertSee(__('chatbot.after_hours'))->assertSee(__('chatbot.buttons.callback'));
    $bot = WebChatMessage::query()->where('author', ChatAuthor::Bot)->latest('id')->first();
    $index = collect($bot->buttons)->search(fn (array $button): bool => $button['type'] === 'callback');

    $widget->call('press', $index)->assertSee('data-chat-form="callback"', false);
});

it('menu resets: the form closes and a chat not waiting for staff goes back to the bot', function () {
    [$chat, $token] = wcChat(['dialog' => WebChatDialog::withForm(LeadFlow::Callback), 'mode' => ChatMode::Manager, 'handoff_at' => now()->subHour(), 'last_staff_reply_at' => now()->subMinutes(30)]);

    wgWidget($token)->call('menu')->assertSet('mode', 'bot')->assertDontSee('data-chat-form', false);

    $chat->refresh();
    expect($chat->dialog)->toBeNull()->and($chat->ai_context_at)->not->toBeNull();
});

it('end_chat_closes_and_forgets_the_cookie_the_next_send_starts_fresh', function () {
    [$chat, $token] = wcChat();
    $widget = wgWidget($token);
    $widget->call('end')->assertSet('notice', 'ended')->assertDispatched('chat-ended')->assertSee(__('chatbot.widget.ended'));

    $chat->refresh();
    $forgotten = Cookie::queued(WebChatToken::cookieName());

    expect($chat->closed_at)->not->toBeNull()
        ->and($chat->ai_context_at)->not->toBeNull()
        ->and($forgotten?->getValue())->toBe('');

    $widget->call('send', 'hello');
    expect(WebChat::query()->count())->toBe(2);
});

it('a banned chat is unavailable and keeps its cookie', function () {
    [$chat, $token] = wcChat(['banned_at' => now()]);

    wgWidget($token)->assertSee(__('chatbot.widget.unavailable'))->call('send', 'hello')->call('end');

    expect(WebChatMessage::query()->count())->toBe(0)
        ->and(Cookie::queued(WebChatToken::cookieName()))->toBeNull()
        ->and($chat->fresh()->closed_at)->toBeNull();
});

it('shows the typing bubble only while an AI turn is pending (unreachable in C5)', function () {
    [$chat, $token] = wcChat();
    wgWidget($token)->assertSet('pending', false)->assertDontSee('data-chat-typing', false);

    DB::table('web_chats')->where('id', $chat->id)->update(['dialog' => json_encode(['ai' => 7])]);
    wgWidget($token)->assertSet('pending', true)->assertSee('data-chat-typing', false);
});

it('the press action is reachable without a chat only through topic and handoff', function () {
    expect(app(PressWebChatButton::class)->press(0, 'en')->status)->toBe('ignored')
        ->and(app(PressWebChatButton::class)->menu('en')->status)->toBe('ignored')
        ->and(WebChat::query()->count())->toBe(0);
});

it('has every widget string in uz, ru and en', function () {
    $keys = fn (string $locale): array => array_keys(Arr::dot(require lang_path("{$locale}/chatbot.php")));
    $widget = fn (array $keys): array => array_values(array_filter($keys, fn (string $key): bool => str_starts_with($key, 'widget.')));

    expect($widget($keys('ru')))->toEqualCanonicalizing($widget($keys('en')))
        ->and($widget($keys('uz')))->toEqualCanonicalizing($widget($keys('en')))
        ->and($widget($keys('en')))->not->toBeEmpty();

    foreach (['uz', 'ru', 'en'] as $locale) {
        foreach (Arr::dot((require lang_path("{$locale}/chatbot.php"))['widget']) as $text) {
            expect($text)->not->toBe('')->not->toMatch('/скидк|дешев|arzon|chegirma|discount|cheap/iu');
        }

        expect(Arr::dot((require lang_path("{$locale}/telegram_inbox.php"))['web']))->toHaveKeys(['handoff_line', 'ended_line', 'contact_line']);
    }

    foreach (Arr::dot((require lang_path('uz/chatbot.php'))['widget']) as $text) {
        expect($text)->not->toMatch("/[oOgG]['‘’]/u");
    }
});
