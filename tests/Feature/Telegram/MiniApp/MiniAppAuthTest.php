<?php

use App\Enums\TelegramUserState;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TelegramUser;
use App\Support\Telegram\MiniApp\TmaSession;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../../../Helpers/MiniApp.php';

/**
 * Plan 20 M1: the sign-in POST (MA-2, MA-6, MA-7, MA-11, MA-12, MA-13).
 */
beforeEach(function () {
    $this->withoutVite();
    tmaEnabled();
});

it('signs a registered user in and goes home in the user locale', function () {
    $user = tmaUser(['locale' => 'ru']);

    tmaAuth(tmaInitData($user->telegram_id))
        ->assertOk()
        ->assertJson(['redirect' => route('tma.home', ['locale' => 'ru'])]);

    expect(session(TmaSession::USER))->toBe($user->id)
        ->and(session(TmaSession::TELEGRAM_ID))->toBe($user->telegram_id);
});

it('shows the reason screen', function (Closure $setup, string $reason) {
    $telegramId = $setup();

    tmaAuth(tmaInitData($telegramId))
        ->assertOk()
        ->assertJson(['redirect' => route('tma.unavailable', ['locale' => 'uz', 'reason' => $reason])]);

    expect(session(TmaSession::USER))->toBeNull();
})->with([
    'unknown' => [fn () => 777000111, 'register'],
    'not registered' => [fn () => TelegramUser::factory()->unregistered()->create()->telegram_id, 'register'],
    'registered_at missing' => [fn () => TelegramUser::factory()->create(['state' => TelegramUserState::Registered, 'registered_at' => null])->telegram_id, 'register'],
    'banned' => [fn () => TelegramUser::factory()->registered()->banned()->create()->telegram_id, 'banned'],
    'blocked' => [fn () => TelegramUser::factory()->registered()->blocked()->create()->telegram_id, 'blocked'],
]);

it('fails closed while the Mini App or the bot is off (MA-13)', function (bool $miniApp, bool $bot) {
    tmaEnabled(miniApp: $miniApp, bot: $bot);
    $user = tmaUser();

    tmaAuth(tmaInitData($user->telegram_id))->assertJson(['redirect' => route('tma.unavailable', ['locale' => 'uz', 'reason' => 'disabled'])]);
    expect(session(TmaSession::USER))->toBeNull();
})->with([[false, true], [true, false], [false, false]]);

it('answers 503 without a bot token (MA-13)', function () {
    freshSecrets(['services.telegram.bot_token' => '']);

    tmaAuth(tmaInitData(tmaUser()->telegram_id))->assertStatus(503);
});

it('refuses forged data with 403 and never echoes or logs it (MA-2, MA-11)', function () {
    Log::spy();
    $raw = str_replace('hash=', 'hash=0', tmaInitData(tmaUser()->telegram_id));

    $response = tmaAuth($raw)->assertForbidden();

    expect($response->getContent())->not->toContain('query_id')
        ->and(session()->all())->not->toHaveKey('init_data')
        ->and(json_encode(session()->all()))->not->toContain('AAHdF6IQ');
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('warning');
});

it('keeps init_data out of the flashed input (MA-11)', function () {
    $this->post(route('tma.auth'), ['init_data' => 'x'], ['Accept' => 'text/html']);

    expect(session('_old_input.init_data'))->toBeNull();
});

it('counts the per-Telegram-id budget only after the signature checked (MA-12)', function () {
    $user = tmaUser();

    foreach (range(1, 25) as $attempt) {
        tmaAuth(str_replace('hash=', 'hash=0', tmaInitData($user->telegram_id)))->assertForbidden();
    }

    // A minute later the per-IP route budget is fresh; the per-user one (10 min) never counted the forgeries.
    $this->travel(61)->seconds();
    tmaAuth(tmaInitData($user->telegram_id))->assertOk();

    foreach (range(2, 20) as $attempt) {
        tmaAuth(tmaInitData($user->telegram_id))->assertOk();
    }

    tmaAuth(tmaInitData($user->telegram_id))->assertStatus(429);
});

it('throttles sign-ins per IP', function () {
    foreach (range(1, 30) as $attempt) {
        tmaAuth('x');
    }

    tmaAuth('x')->assertStatus(429);
});

it('regenerates the session and the CSRF token, and wipes another account (MA-6)', function () {
    $first = tmaUser();
    $second = tmaUser(['phone' => '+998907654321']);
    $this->withSession(['tma.other' => 'left over', TmaSession::USER => $first->id, TmaSession::TELEGRAM_ID => $first->telegram_id]);
    $oldId = session()->getId();
    $oldToken = session()->token();

    tmaAuth(tmaInitData($second->telegram_id))->assertOk();

    expect(session()->getId())->not->toBe($oldId)
        ->and(session()->token())->not->toBe($oldToken)
        ->and(session('tma.other'))->toBeNull()
        ->and(session(TmaSession::USER))->toBe($second->id);
});

it('opens deep links from the signed start_param only (MA-7)', function () {
    $user = tmaUser();
    $product = Product::factory()->create();
    $category = Category::query()->findOrFail($product->category_id);
    $order = Order::factory()->telegram($user)->create();
    $foreign = Order::factory()->telegram(tmaUser(['phone' => '+998907654321']))->create();

    $to = fn (string $param) => tmaAuth(tmaInitData($user->telegram_id, ['start_param' => $param]))->json('redirect');

    expect($to('p'.$product->id))->toBe(route('tma.product', ['locale' => 'uz', 'product' => $product->slug]))
        ->and($to('c'.$category->id))->toBe(route('tma.category', ['locale' => 'uz', 'category' => $category->slug]))
        ->and($to('orders'))->toBe(route('tma.orders', ['locale' => 'uz']))
        ->and($to('o'.$order->id))->toBe(route('tma.orders.show', ['locale' => 'uz', 'order' => $order->id]))
        ->and($to('o'.$foreign->id))->toBe(route('tma.orders', ['locale' => 'uz']))
        ->and($to('p999999'))->toBe(route('tma.home', ['locale' => 'uz']))
        ->and($to('https://evil.example'))->toBe(route('tma.home', ['locale' => 'uz']));
});

it('takes "next" only from the same whitelist (MA-7)', function () {
    $user = tmaUser();

    expect(tmaAuth(tmaInitData($user->telegram_id), 'orders')->json('redirect'))->toBe(route('tma.orders', ['locale' => 'uz']))
        ->and(tmaAuth(tmaInitData($user->telegram_id), '//evil.example')->json('redirect'))->toBe(route('tma.home', ['locale' => 'uz']))
        ->and(tmaAuth(tmaInitData($user->telegram_id), 'admin.dashboard')->json('redirect'))->toBe(route('tma.home', ['locale' => 'uz']));
});

it('renders the bootstrap page with the auth endpoint and a whitelisted next only', function () {
    $this->get('/tma?next=orders')->assertOk()->assertSee('data-tma-boot', false)->assertSee('data-next="orders"', false)->assertSee(route('tma.auth'), false);
    $this->get('/tma?next=https://evil.example')->assertOk()->assertDontSee('data-next', false);
});

it('renders every reason screen', function (string $reason) {
    $this->get(route('tma.unavailable', ['locale' => 'en', 'reason' => $reason]))
        ->assertOk()
        ->assertSee(__('tma.unavailable.'.$reason.'.title', [], 'en'));
})->with(['register', 'banned', 'blocked', 'expired', 'disabled', 'browser', 'phone']);

it('links the register screen to the bot only with a valid stored username', function () {
    $this->get(route('tma.unavailable', ['locale' => 'uz', 'reason' => 'register']))->assertDontSee('t.me/', false);

    Setting::query()->create(['key' => 'telegram.bot_username', 'group' => 'telegram', 'value' => 'poson_test_bot']);
    $this->get(route('tma.unavailable', ['locale' => 'uz', 'reason' => 'register']))->assertSee('data-tg-link="https://t.me/poson_test_bot?start=tma"', false);

    Setting::query()->where('key', 'telegram.bot_username')->update(['value' => json_encode('bad name/../x')]);
    $this->get(route('tma.unavailable', ['locale' => 'uz', 'reason' => 'register']))->assertDontSee('t.me/', false);
});
