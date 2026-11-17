<?php

require_once __DIR__.'/WidgetHelpers.php';

use App\Enums\SettingsArea;
use App\Models\Page;
use App\Models\Setting;
use App\Models\WebChat;
use App\Support\Chat\ChatbotSwitch;
use App\Support\Chat\ChatSettings;
use App\Support\Chat\WebChatSwitch;
use App\Support\Chat\WebChatToken;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * Plan 28 C5b: the chat launcher in layouts::site (§19.3, §19.5, CB-23,
 * WC-6, WC-18; §20 plan change 6): a page view runs no chat query, sets no
 * chat cookie and makes no Livewire request; the widget sits in an x-if
 * template and is mounted only after the click.
 */
beforeEach(fn () => wcSetup());

function wgSetting(string $name, mixed $value): void
{
    Setting::query()->updateOrCreate(['key' => ChatSettings::key($name)], ['group' => SettingsArea::Chatbot->group(), 'value' => $value]);
    ChatbotSwitch::forget();
}

it('page_view_sets_no_chat_cookie_runs_no_chat_query_and_mounts_nothing', function () {
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response = $this->get('/en')->assertOk();
    $html = $response->getContent();

    expect(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'web_chat')))->toBe([])
        ->and(collect($response->headers->getCookies())->map->getName()->all())->not->toContain(WebChatToken::cookieName())
        ->and(WebChat::query()->count())->toBe(0);

    // The static launcher: an aria-label, no text node, an inline icon.
    expect($html)->toContain('data-chat-launcher')
        ->toContain('aria-label="'.__('chatbot.widget.open').'"')
        ->toContain('x-data="{ open: false, mounted: false, unread: false }"')
        ->toContain('<template x-if="mounted">')
        ->toContain('print:hidden')
        ->not->toContain('wire:poll');

    // The widget is only inside the x-if template, as a lazy placeholder whose mount() has not run (no locale yet).
    $template = substr($html, strpos($html, '<template x-if="mounted">'));
    expect(substr_count($html, 'site.chat.widget'))->toBe(substr_count($template, 'site.chat.widget'))
        ->and($template)->toContain('x-intersect="$wire.__lazyLoad(')
        ->toContain('&quot;locale&quot;:&quot;&quot;')
        ->toContain('data-chat-placeholder');
});

it('reads the switch from the cache on later page views (no settings query for the launcher)', function () {
    $this->get('/en')->assertOk();
    expect(cache()->get(WebChatSwitch::CACHE_KEY))->toBe(1);

    Setting::query()->where('key', ChatbotSwitch::WEB_KEY)->update(['value' => false]);
    ChatbotSwitch::forget();

    expect(cache()->has(WebChatSwitch::CACHE_KEY))->toBeFalse();
    $this->get('/en')->assertOk()->assertDontSee('data-chat-launcher', false);
});

it('kill_switch_hides_the_launcher', function (Closure $off) {
    $off();
    WebChatSwitch::forget();

    $this->get('/en')->assertOk()->assertDontSee('data-chat-launcher', false)->assertDontSee('site.chat.widget', false);
})->with([
    'web switch off' => [fn () => Setting::query()->where('key', ChatbotSwitch::WEB_KEY)->update(['value' => false])],
    'widget not ready' => [fn () => config(['chatbot.web_ready' => false])],
    'privacy page unpublished' => [fn () => Page::query()->where('slug', 'privacy')->update(['is_active' => false])],
]);

it('widget_not_rendered_on_checkout_and_on_the_cart_with_no_cart', function () {
    config(['site.cart_enabled' => true]);

    $this->get('/en/checkout')->assertOk()->assertDontSee('data-chat-launcher', false);
    $this->get('/en/cart')->assertOk()->assertSee('data-chat-launcher', false);

    wgSetting('widget_pages', 'no_cart');
    WebChatSwitch::forget();

    $this->get('/en/cart')->assertOk()->assertDontSee('data-chat-launcher', false);
    $this->get('/en')->assertOk()->assertSee('data-chat-launcher', false);
});

it('follows the position and mobile settings and clears the product page sticky bar', function () {
    wgSetting('widget_position', 'left');
    wgSetting('widget_mobile', false);

    $html = $this->get('/en')->assertOk()->getContent();
    expect($html)->toContain('class="print:hidden max-sm:hidden"')->toContain('left-4 sm:left-6');

    $product = wcProduct('QX7001');
    expect($this->get('/en/product/'.$product->slug)->assertOk()->getContent())->toContain('bottom-[88px] md:bottom-6');
});

it('auto_mount_only_with_cookie_and_staff_activity_24h', function () {
    [$quiet, $quietToken] = wcChat();
    [$active, $activeToken] = wcChat(['last_staff_reply_at' => now()->subHours(2)]);

    $this->withCookie(WebChatToken::cookieName(), $quietToken)->get('/en')->assertOk()
        ->assertSee('mounted: false', false);

    $html = $this->withCookie(WebChatToken::cookieName(), $activeToken)->get('/en')->assertOk()->getContent();

    expect($html)->toContain('x-data="{ open: false, mounted: true, unread: true }"')
        ->toContain('x-init="$wire.__lazyLoad(')
        ->not->toContain($activeToken);
});

it('the page path for the chat comes from the page request, without the query string', function () {
    $html = $this->get('/ru?utm_source=x')->assertOk()->getContent();

    expect($html)->toContain('&quot;page&quot;:&quot;\/ru&quot;')->not->toContain('utm_source=x&quot;');
});
