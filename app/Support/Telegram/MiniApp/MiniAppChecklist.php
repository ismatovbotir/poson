<?php

namespace App\Support\Telegram\MiniApp;

use App\Enums\BuyerType;
use App\Enums\DeliveryType;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\WebAppButton;
use App\Support\Orders\PaymentContext;
use App\Support\Orders\PaymentOptions;
use App\Support\Telegram\Bot\BotChecklist;
use App\Support\Telegram\Bot\BotSwitch;

/**
 * Prerequisites for switching the Telegram Mini App on (plan 20 §10, MA-34).
 * Shown on the admin Mini App page and evaluated again, server-side, by
 * SetMiniAppEnabled on every enable. No Telegram call; each item is true/false:
 *
 *  - bot_enabled: the customer bot switch is on (BotSwitch);
 *  - token: a bot token is configured;
 *  - app_url: APP_URL is https and the Mini App entry is a valid web_app URL;
 *  - privacy: the privacy page is active and reviewed (BotChecklist);
 *  - username: a valid bot username is stored ("Check bot");
 *  - payment: at least one Mini App payment method for some buyer type and
 *    delivery (PaymentOptions::forMiniApp()).
 *
 * info() items never block: cart_enabled (site.cart_enabled; off = catalog
 * and requests only).
 */
final class MiniAppChecklist
{
    public const ITEMS = ['bot_enabled', 'token', 'app_url', 'privacy', 'username', 'payment'];

    public function __construct(
        private readonly TelegramClient $client,
        private readonly BotChecklist $botChecklist,
        private readonly PaymentOptions $paymentOptions,
    ) {}

    /**
     * @return array<string, bool> item => ok, in ITEMS order
     */
    public function evaluate(): array
    {
        return [
            'bot_enabled' => BotSwitch::isEnabled(),
            'token' => $this->client->isConfigured(),
            'app_url' => WebAppButton::miniApp('Catalog') !== null,
            'privacy' => (bool) $this->botChecklist->local()['privacy'],
            'username' => BotUsername::get() !== null,
            'payment' => $this->hasPaymentMethod(),
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function info(): array
    {
        return ['cart_enabled' => (bool) config('site.cart_enabled')];
    }

    /**
     * @param  array<string, bool>  $items
     * @return list<string>
     */
    public static function failing(array $items): array
    {
        return array_keys(array_filter($items, fn (bool $ok): bool => ! $ok));
    }

    private function hasPaymentMethod(): bool
    {
        foreach (BuyerType::cases() as $buyerType) {
            foreach (DeliveryType::cases() as $deliveryType) {
                if ($this->paymentOptions->forMiniApp(new PaymentContext($deliveryType, $buyerType, 1, true)) !== []) {
                    return true;
                }
            }
        }

        return false;
    }
}
