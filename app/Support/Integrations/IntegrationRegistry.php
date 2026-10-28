<?php

namespace App\Support\Integrations;

use App\Enums\PaymeMode;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Every integration value editable in Settings → Integrations (plan 04 S3, §2).
 * Secrets (encrypted in integration_secrets): Telegram bot token and
 * webhook secret, Payme keys, Click secret key. Everything else is a
 * non-secret value in "settings" (group integrations).
 *
 * App\Support\Secrets resolves each field (non-empty .env > admin value >
 * default) and writes it to its config paths, so code keeps reading
 * config('services.…'). Unregistered (orphan) keys in the DB are ignored.
 */
final class IntegrationRegistry
{
    public const GROUPS = ['telegram', 'payme', 'click'];

    /**
     * Telegram chat ID (-100…, a user/group ID) or a public @channel name.
     */
    public const TELEGRAM_CHAT = '/^(-?\d{1,20}|@[A-Za-z][A-Za-z0-9_]{4,31})$/';

    /**
     * Bot token from @BotFather: "<bot id>:<35 chars>".
     */
    public const TELEGRAM_TOKEN = '/^\d{5,15}:[A-Za-z0-9_-]{30,60}$/';

    /**
     * Telegram secret_token: 1–256 of A–Z, a–z, 0–9, "_" and "-" (we require 32+).
     */
    public const TELEGRAM_WEBHOOK_SECRET = '/^[A-Za-z0-9_-]{32,256}$/';

    /**
     * Payme cashbox (merchant) ID: 24 hex characters, e.g. 587f72c72cac0d162c722ae2.
     */
    public const PAYME_MERCHANT_ID = '/^[0-9a-f]{24}$/i';

    /**
     * Generic API key: printable ASCII without spaces.
     */
    public const API_KEY = '/^[\x21-\x7E]+$/';

    /**
     * @var array<string, IntegrationField>|null
     */
    private static ?array $fields = null;

    /**
     * All fields keyed by key, in display order.
     *
     * @return array<string, IntegrationField>
     */
    public static function fields(): array
    {
        return self::$fields ??= self::build();
    }

    /**
     * Rebuild on next use (the supported locales come from config).
     */
    public static function forget(): void
    {
        self::$fields = null;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::fields());
    }

    /**
     * @throws InvalidArgumentException for an unknown key
     */
    public static function field(string $key): IntegrationField
    {
        return self::fields()[$key] ?? throw new InvalidArgumentException("Unknown integration field [{$key}].");
    }

    /**
     * Fields of one section.
     *
     * @return array<string, IntegrationField>
     */
    public static function group(string $group): array
    {
        return array_filter(self::fields(), fn (IntegrationField $field): bool => $field->group === $group);
    }

    /**
     * @return array<string, IntegrationField>
     */
    public static function secrets(): array
    {
        return array_filter(self::fields(), fn (IntegrationField $field): bool => $field->secret);
    }

    /**
     * @return array<string, IntegrationField>
     */
    public static function settings(): array
    {
        return array_filter(self::fields(), fn (IntegrationField $field): bool => ! $field->secret);
    }

    /**
     * Webhook URLs to paste into the Payme / Click merchant cabinets
     * (routes added by the payment modules; built from APP_URL).
     *
     * @return array{payme: string, click_prepare: string, click_complete: string}
     */
    public static function webhookUrls(): array
    {
        $base = rtrim((string) config('app.url'), '/');

        return [
            'payme' => $base.'/webhooks/payme',
            'click_prepare' => $base.'/webhooks/click/prepare',
            'click_complete' => $base.'/webhooks/click/complete',
        ];
    }

    /**
     * Whether APP_URL is a public https address Payme / Click can call
     * (not http, localhost, 127.0.0.1 or a *.test host).
     */
    public static function webhooksArePublic(): bool
    {
        $url = (string) config('app.url');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            && $host !== ''
            && ! in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)
            && ! str_ends_with($host, '.test')
            && ! str_ends_with($host, '.localhost');
    }

    /**
     * @return array<string, IntegrationField>
     */
    private static function build(): array
    {
        $digits = ['string', 'regex:/^\d{1,12}$/'];
        $locales = array_values(array_filter((array) config('app.supported_locales', ['uz', 'ru', 'en']), is_string(...)));

        $fields = [
            // Telegram
            new IntegrationField('telegram.bot_token', 'telegram', ['services.telegram.bot_token'], 'TELEGRAM_BOT_TOKEN', secret: true, rules: ['string', 'max:100', 'regex:'.self::TELEGRAM_TOKEN]),
            new IntegrationField('telegram.webhook_secret', 'telegram', ['services.telegram.webhook_secret'], 'TELEGRAM_WEBHOOK_SECRET', secret: true, rules: ['string', 'regex:'.self::TELEGRAM_WEBHOOK_SECRET], generated: true),
            new IntegrationField('telegram.chats.sales', 'telegram', ['services.telegram.chats.sales'], 'TELEGRAM_SALES_CHAT_ID', rules: ['string', 'max:40', 'regex:'.self::TELEGRAM_CHAT]),
            new IntegrationField('telegram.chats.service', 'telegram', ['services.telegram.chats.service'], 'TELEGRAM_SERVICE_CHAT_ID', rules: ['string', 'max:40', 'regex:'.self::TELEGRAM_CHAT]),
            new IntegrationField('telegram.chats.finance', 'telegram', ['services.telegram.chats.finance'], 'TELEGRAM_FINANCE_CHAT_ID', rules: ['string', 'max:40', 'regex:'.self::TELEGRAM_CHAT]),
            // Plan 05c: new-lead alerts on/off and the language of every Telegram message (decision 2026-10-22: Uzbek).
            new IntegrationField('telegram.notify_new_leads', 'telegram', ['services.telegram.notify_new_leads'], 'TELEGRAM_NOTIFY_NEW_LEADS', type: IntegrationField::TYPE_BOOL, rules: ['boolean'], default: true),
            // Plan 09: finance-chat alert on price API problems (on from launch, decision 2026-10-27).
            new IntegrationField('telegram.notify_price_sync', 'telegram', ['services.telegram.notify_price_sync'], 'TELEGRAM_NOTIFY_PRICE_SYNC', type: IntegrationField::TYPE_BOOL, rules: ['boolean'], default: true),
            new IntegrationField('telegram.locale', 'telegram', ['services.telegram.locale'], 'TELEGRAM_LOCALE', type: IntegrationField::TYPE_SELECT, rules: ['string', Rule::in($locales)], default: 'uz', options: $locales),

            // Payme
            new IntegrationField('payme.merchant_id', 'payme', ['services.payme.merchant_id'], 'PAYME_MERCHANT_ID', rules: ['string', 'regex:'.self::PAYME_MERCHANT_ID], lowercase: true),
            new IntegrationField('payme.key', 'payme', ['services.payme.key'], 'PAYME_KEY', secret: true, rules: ['string', 'min:10', 'max:300', 'regex:'.self::API_KEY]),
            new IntegrationField('payme.test_key', 'payme', ['services.payme.test_key'], 'PAYME_TEST_KEY', secret: true, rules: ['string', 'min:10', 'max:300', 'regex:'.self::API_KEY]),
            new IntegrationField('payme.mode', 'payme', ['services.payme.mode'], 'PAYME_MODE', type: IntegrationField::TYPE_SELECT, rules: ['string', Rule::enum(PaymeMode::class)], default: PaymeMode::Test->value, options: array_column(PaymeMode::cases(), 'value')),

            // Click
            new IntegrationField('click.service_id', 'click', ['services.click.service_id'], 'CLICK_SERVICE_ID', rules: $digits),
            new IntegrationField('click.merchant_id', 'click', ['services.click.merchant_id'], 'CLICK_MERCHANT_ID', rules: $digits),
            new IntegrationField('click.merchant_user_id', 'click', ['services.click.merchant_user_id'], 'CLICK_MERCHANT_USER_ID', rules: $digits),
            new IntegrationField('click.secret_key', 'click', ['services.click.secret_key'], 'CLICK_SECRET_KEY', secret: true, rules: ['string', 'min:8', 'max:300', 'regex:'.self::API_KEY]),
        ];

        $keyed = [];

        foreach ($fields as $field) {
            $keyed[$field->key] = $field;
        }

        return $keyed;
    }
}
