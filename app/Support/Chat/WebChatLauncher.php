<?php

namespace App\Support\Chat;

use App\Actions\Chat\Web\ResolveWebChat;

/**
 * What the site layout's chat launcher needs on a page view (plan 28 C5
 * §19.3, §19.5, CB-23, WC-18): null = not rendered at all.
 *
 * Cost on a page view without a chat cookie: no query on a warm cache (the
 * cached switch, the cached chatbot settings), no cookie and no session
 * write, no Livewire request. Hidden when the widget is switched off
 * (WebChatSwitch::isOpenCached), on checkout, on the cart with the
 * "no_cart" pages setting; the layout's `chat` prop hides it elsewhere.
 *
 * autoMount (the unread dot, the widget loads right after the page): only
 * with a well-formed chat cookie AND staff activity in the last 24 hours
 * (one indexed web_chats query, only for those visitors).
 *
 * page: the path of this page request (rendered into the widget's locked
 * mount prop, checksummed by Livewire), cleaned by WebChatPage (WC-10). This
 * runs on the page GET itself, never in a Livewire update request.
 */
final class WebChatLauncher
{
    /**
     * @return array{position: string, mobile: bool, raised: bool, autoMount: bool, page: string|null}|null
     */
    public static function state(): ?array
    {
        if (! WebChatSwitch::isOpenCached()) {
            return null;
        }

        $route = request()->route()?->getName();

        if ($route === 'site.checkout') {
            return null;
        }

        $settings = ChatSettings::all();

        if ($settings['widget_pages'] === 'no_cart' && $route === 'site.cart') {
            return null;
        }

        return [
            'position' => $settings['widget_position'] === 'left' ? 'left' : 'right',
            'mobile' => $settings['widget_mobile'],
            'raised' => $route === 'site.product',
            'autoMount' => self::hasRecentStaffActivity(),
            'page' => WebChatPage::clean(request()->getPathInfo()),
        ];
    }

    private static function hasRecentStaffActivity(): bool
    {
        if (app(WebChatToken::class)->current() === null) {
            return false;
        }

        $chat = app(ResolveWebChat::class)->handle()->chat;

        return $chat !== null && $chat->last_staff_reply_at !== null && $chat->last_staff_reply_at->gte(now()->subDay());
    }
}
