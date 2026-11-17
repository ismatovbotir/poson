<?php

require_once __DIR__.'/../../Chat/ChatBotHelpers.php';
require_once __DIR__.'/../../Chat/Web/WebChatHelpers.php';

use App\Livewire\Site\Chat\Widget;
use App\Support\Chat\WebChatToken;
use Illuminate\Support\Facades\Cookie;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Shared helpers of the plan 28 C5b widget tests (prefixed "wg").
 */

/**
 * The widget as the lazy load mounts it, for a visitor with this chat cookie (null: none).
 */
function wgWidget(?string $token = null, string $page = '/en/product/zebra-zd421', string $locale = 'en'): Testable
{
    wcRequest($token);
    app()->setLocale($locale);

    if ($token !== null) {
        app(WebChatToken::class)->issue($token);
        Cookie::flushQueuedCookies();
    }

    return Livewire::test(Widget::class, ['page' => $page]);
}
