<?php

require_once __DIR__.'/WebChatHelpers.php';

use App\Actions\Chat\Web\ResolveWebChat;
use App\Models\WebChat;
use App\Support\Chat\WebChatIp;
use App\Support\Chat\WebChatToken;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Plan 28 C5a, WC-5 / WC-17: the cookie token and the IP hash.
 */
it('WC5_generates_43_char_base64url_tokens_and_checks_the_shape_before_hashing', function () {
    $token = WebChatToken::generate();

    expect($token)->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and(WebChatToken::isWellFormed($token))->toBeTrue()
        ->and(WebChatToken::isWellFormed(substr($token, 1)))->toBeFalse()
        ->and(WebChatToken::isWellFormed($token.'='))->toBeFalse()
        ->and(WebChatToken::isWellFormed(['x']))->toBeFalse()
        ->and(WebChatToken::hash($token))->toBe(hash('sha256', $token))
        ->and(WebChatToken::generate())->not->toBe($token);
});

it('WC5_sets_httponly_lax_cookie_locally_and_host_prefixed_secure_without_domain_in_production', function () {
    config(['session.domain' => '.poson.uz']);
    $local = WebChatToken::cookie('x', 1440);

    app()->instance('env', 'production');
    $production = WebChatToken::cookie('x', 1440);
    app()->instance('env', 'testing');

    expect($local->getName())->toBe('poson_chat')
        ->and($local->isHttpOnly())->toBeTrue()
        ->and($local->getSameSite())->toBe(Cookie::SAMESITE_LAX)
        ->and($local->getDomain())->toBeNull()
        ->and($production->getName())->toBe('__Host-poson_chat')
        ->and($production->isSecure())->toBeTrue()
        ->and($production->getPath())->toBe('/')
        ->and($production->getDomain())->toBeNull()
        ->and($production->isHttpOnly())->toBeTrue();
});

it('WC5_the_cookie_is_encrypted_and_a_plain_or_value_swapped_cookie_is_no_chat', function () {
    [$chat, $token] = wcChat();
    Route::middleware('web')->get('/_wc-test', fn () => response()->json(['chat' => app(ResolveWebChat::class)->handle()->chat?->id]));

    $encrypted = Crypt::encryptString(CookieValuePrefix::create('poson_chat', Crypt::getKey()).$token);
    $swapped = Crypt::encryptString(CookieValuePrefix::create('poson_cart', Crypt::getKey()).$token);

    $this->withUnencryptedCookie('poson_chat', $encrypted)->get('/_wc-test')->assertJson(['chat' => $chat->id]);
    $this->withUnencryptedCookie('poson_chat', $token)->get('/_wc-test')->assertJson(['chat' => null]);
    $this->withUnencryptedCookie('poson_chat', $swapped)->get('/_wc-test')->assertJson(['chat' => null]);
});

it('WC5_never_stores_the_token_only_its_hash', function () {
    wcSetup(defaults: false);
    wcSend('Hello');
    $token = (string) wcIssuedToken();

    expect(WebChat::query()->where('token_hash', $token)->exists())->toBeFalse()
        ->and(WebChat::query()->where('token_hash', hash('sha256', $token))->exists())->toBeTrue()
        ->and(json_encode(WebChat::query()->sole()->getAttributes()))->not->toContain($token);
});

it('WC17_same_64_same_hash_next_day_different_and_never_the_analytics_hash', function () {
    $a = WebChatIp::hash('2001:db8:1:2:aaaa::1');
    $b = WebChatIp::hash('2001:db8:1:2:bbbb::9');
    $other = WebChatIp::hash('2001:db8:1:3::1');

    $this->travel(1)->days();

    expect($a)->toBe($b)
        ->and($a)->not->toBe($other)
        ->and($a)->toHaveLength(64)
        ->and(WebChatIp::hash('2001:db8:1:2:aaaa::1'))->not->toBe($a)
        ->and(cache()->has('chat:ip-salt:'.now()->format('Ymd')))->toBeTrue()
        ->and(cache()->get('chat:ip-salt:'.now()->format('Ymd')))->not->toBe(cache()->get('analytics:salt:'.now()->format('Y-m-d')));
});
