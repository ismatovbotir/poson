<?php

use App\Actions\Chat\InstallChatbotDefaults;
use App\Actions\Chat\Web\PostedMessage;
use App\Actions\Chat\Web\PostVisitorMessage;
use App\Enums\SettingsArea;
use App\Models\Page;
use App\Models\Product;
use App\Models\Setting;
use App\Models\WebChat;
use App\Support\Chat\ChatbotSwitch;
use App\Support\Chat\RuleIndex;
use App\Support\Chat\WebChatToken;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Shared helpers of the plan 28 C5a website chat tests. Prefixed "wc" so
 * they never collide with the Telegram chat helpers.
 */
function wcSetup(bool $defaults = true): void
{
    Http::preventStrayRequests();
    Queue::fake();
    config(['chatbot.web_ready' => true]);
    Page::factory()->system('privacy')->create(['is_active' => true, 'needs_review' => false]);
    Setting::query()->updateOrCreate(['key' => ChatbotSwitch::WEB_KEY], ['group' => SettingsArea::Chatbot->group(), 'value' => true]);
    ChatbotSwitch::forget();

    if ($defaults) {
        app(InstallChatbotDefaults::class)->handle();
    }

    RuleIndex::bump();
}

/**
 * A fresh request: new scoped instances (token, switch memo) and the cookie as the browser would send it.
 */
function wcRequest(?string $token = null, string $ip = '203.0.113.7'): void
{
    app()->forgetScopedInstances();
    app('request')->cookies->remove(WebChatToken::cookieName());

    if ($token !== null) {
        app('request')->cookies->set(WebChatToken::cookieName(), $token);
    }

    app('request')->server->set('REMOTE_ADDR', $ip);
    Cookie::flushQueuedCookies();
}

/**
 * Send a visitor message in a fresh request with this cookie token.
 */
function wcSend(mixed $text, ?string $token = null, string $locale = 'en', ?string $page = '/en', string $ip = '203.0.113.7'): PostedMessage
{
    wcRequest($token, $ip);

    return app(PostVisitorMessage::class)->handle($text, $locale, $page);
}

/**
 * The token of the cookie queued in this request (null: none).
 */
function wcIssuedToken(): ?string
{
    $cookie = Cookie::queued(WebChatToken::cookieName());

    return $cookie === null || $cookie->getValue() === '' ? null : $cookie->getValue();
}

/**
 * A chat with a known cookie token.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: WebChat, 1: string}
 */
function wcChat(array $attributes = [], ?Closure $factory = null): array
{
    $token = WebChatToken::generate();
    $builder = WebChat::factory()->withToken($token);

    if ($factory !== null) {
        $builder = $factory($builder);
    }

    return [$builder->create($attributes), $token];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function wcProduct(string $model, array $attributes = []): Product
{
    return Product::factory()->create(array_merge([
        'model' => $model,
        'sku' => 'SKU-'.$model,
        'name' => ['uz' => "Scanner {$model}", 'ru' => "Scanner {$model}", 'en' => "Scanner {$model}"],
    ], $attributes));
}
