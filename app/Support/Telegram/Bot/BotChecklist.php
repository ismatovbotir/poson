<?php

namespace App\Support\Telegram\Bot;

use App\Actions\Telegram\ConfigureTelegramWebhook;
use App\Models\Page;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Integrations\IntegrationRegistry;
use App\Support\Secrets;

/**
 * Prerequisites for switching the customer bot on (plan 14 v2 §9, TG-37).
 * Evaluated on the bot page and again, server-side, by SetBotEnabled on
 * every enable. Each item is true (ok) or false:
 *
 *  - token: a bot token is configured;
 *  - webhook_secret: a webhook secret is configured;
 *  - app_url: APP_URL is a public https address;
 *  - webhook: Telegram's webhook URL equals the expected one (getWebhookInfo);
 *  - chat: the inbox target (InboxChat: inbox chat, else sales chat) is a
 *    numeric group id (^-[1-9]\d{0,19}$); the getChat check (private group) runs in
 *    ForwardTelegramMedia / the inbox alert, cached (B4-S1/S2);
 *  - privacy: the privacy page exists, is active and no longer needs review.
 */
final class BotChecklist
{
    public const ITEMS = ['token', 'webhook_secret', 'app_url', 'webhook', 'chat', 'privacy'];

    public const PRIVACY_SLUG = 'privacy';

    public function __construct(private readonly TelegramClient $client) {}

    /**
     * @param  array<string, mixed>|null  $webhookInfo  getWebhookInfo result already fetched (null: fetch it)
     * @return array<string, bool> item => ok, in ITEMS order
     */
    public function evaluate(?array $webhookInfo = null): array
    {
        $items = $this->local();
        $items['webhook'] = $items['token'] && $this->webhookMatches($webhookInfo);

        return $items;
    }

    /**
     * Only the items that need no Telegram call; "webhook" is null (not checked yet).
     * Used when the bot page loads (its Telegram calls run afterwards, rate-limited).
     *
     * @return array<string, bool|null>
     */
    public function local(): array
    {
        return [
            'token' => $this->client->isConfigured(),
            'webhook_secret' => Secrets::isConfigured('telegram.webhook_secret'),
            'app_url' => ConfigureTelegramWebhook::expectedUrl() !== null && IntegrationRegistry::webhooksArePublic(),
            'webhook' => null,
            'chat' => InboxChat::id() !== null,
            'privacy' => Page::query()->where('slug', self::PRIVACY_SLUG)->where('is_active', true)->where('needs_review', false)->exists(),
        ];
    }

    /**
     * @param  array<string, bool|null>  $items
     * @return list<string> Items that failed or were not checked
     */
    public static function failing(array $items): array
    {
        return array_keys(array_filter($items, fn (?bool $ok): bool => $ok !== true));
    }

    /**
     * @param  array<string, mixed>|null  $info
     */
    private function webhookMatches(?array $info): bool
    {
        $expected = ConfigureTelegramWebhook::expectedUrl();

        if ($expected === null) {
            return false;
        }

        if ($info === null) {
            try {
                $info = $this->client->withTimeout(5, 3)->getWebhookInfo();
            } catch (TelegramException) {
                return false;
            }
        }

        return is_string($info['url'] ?? null) && hash_equals($expected, $info['url']);
    }
}
