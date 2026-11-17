<?php

namespace App\Actions\Chat\Web;

use App\Enums\ChatMode;
use App\Enums\ConversationStatus;
use App\Models\WebChat;
use App\Support\Chat\WebChatIp;
use App\Support\Chat\WebChatPage;
use App\Support\Chat\WebChatToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A new website chat on the visitor's first message (plan 28 C5 §19.3,
 * CB-19, CB-24, W-2, WC-3, WC-5, WC-10, WC-14, WC-18). Two steps, so no
 * limiter hit ever runs inside a transaction (WC-3):
 *
 *  1. allow(): before any transaction. Refused while the cookie of a banned
 *     chat is present (the caller passes it from ResolveWebChat). Hits, each
 *     atomic and deciding by itself: for 24 h after a chat from this IP hash
 *     was banned 1 new chat per hour from it (a cooldown, not a block: NAT),
 *     else 10 per hour per IP hash; then 300 per hour over all visitors.
 *  2. create(): the row and the cookie. consent_at / consent_version are set
 *     here and only here (sending the first message, with the consent line
 *     shown above the input, is the consent); the token is new, stored as its
 *     hash only, and the cookie goes out after the insert commits.
 *
 * first_page: the caller passes Livewire::originalPath() or the widget's
 * #[Locked] page prop (never Referer, request()->path() or a client
 * argument); WebChatPage::clean() keeps the path only.
 */
class StartWebChat
{
    public function __construct(private readonly WebChatToken $token) {}

    public function allow(string $ipHash, bool $bannedCookie = false): bool
    {
        if ($bannedCookie) {
            return false;
        }

        $limiterIp = WebChatIp::limiterKey();

        [$key, $max] = $this->recentlyBanned()
            ? ['web-chat:new:banned-ip:', (int) config('chatbot.web.new_per_hour_banned_ip', 1)]
            : ['web-chat:new:ip:', (int) config('chatbot.web.new_per_hour_ip', 10)];

        if (RateLimiter::hit($key.$limiterIp, 3600) > $max) {
            return false;
        }

        return RateLimiter::hit('web-chat:new:all', 3600) <= (int) config('chatbot.web.new_per_hour_global', 300);
    }

    public function create(string $locale, ?string $firstPage, string $ipHash): WebChat
    {
        $token = WebChatToken::generate();
        $now = now();

        $chat = new WebChat(['locale' => $locale, 'unread_count' => 0, 'last_message_at' => $now]);
        $chat->forceFill([
            'token_hash' => WebChatToken::hash($token),
            'consent_at' => $now,
            'consent_version' => mb_substr((string) config('chatbot.web.consent_version', ''), 0, 20) ?: null,
            'mode' => ChatMode::Bot,
            'conversation_status' => ConversationStatus::Open,
            'ip_hash' => $ipHash,
            'first_page' => WebChatPage::clean($firstPage),
        ])->save();

        DB::afterCommit(fn () => $this->token->issue($token));

        return $chat;
    }

    /**
     * The stored ip_hash follows the visitor's last activity (today's salt), so
     * a later ban is found by recentlyBanned() (WC-14).
     */
    public function follow(WebChat $chat, string $ipHash): void
    {
        WebChat::query()->whereKey($chat->getKey())
            ->where(fn ($query) => $query->whereNull('ip_hash')->orWhere('ip_hash', '!=', $ipHash))
            ->update(['ip_hash' => $ipHash]);
    }

    /**
     * The cooldown is capped at 24 h: two salt days (today, yesterday) are matched.
     */
    private function recentlyBanned(): bool
    {
        return WebChat::query()
            ->whereIn('ip_hash', WebChatIp::hashes())
            ->where('banned_at', '>=', now()->subHours(min(24, max(1, (int) config('chatbot.web.banned_ip_cooldown_hours', 24)))))
            ->exists();
    }
}
