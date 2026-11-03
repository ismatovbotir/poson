<?php

namespace App\Actions\Telegram;

use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Publish the bot's command menu (/start, /language, /help) in every
 * supported locale via setMyCommands with language_code, plus the default
 * list (no language_code) in Uzbek (plan 14 v2 §9). Audited once (area
 * telegram_bot, key telegram.commands, action updated).
 */
class SetBotCommands
{
    public const COMMANDS = ['start', 'language', 'help'];

    public const AUDIT_KEY = 'telegram.commands';

    public const DEFAULT_LOCALE = 'uz';

    public function __construct(protected TelegramClient $client) {}

    /**
     * @return list<array{command: string, description: string}>
     */
    public static function commandsFor(string $locale): array
    {
        return array_map(fn (string $command): array => [
            'command' => $command,
            'description' => __('telegram.bot_commands.'.$command, [], $locale),
        ], self::COMMANDS);
    }

    /**
     * A user needs telegram.bot; null is the console (system actor).
     *
     * @throws AuthorizationException
     * @throws TelegramBotException no_token | telegram_error
     */
    public function handle(?User $user, ?string $ip): void
    {
        if ($user !== null && ! $user->can('telegram.bot')) {
            throw new AuthorizationException;
        }

        if (! $this->client->isConfigured()) {
            throw new TelegramBotException('no_token');
        }

        try {
            $this->client->setMyCommands(self::commandsFor(self::DEFAULT_LOCALE));

            foreach ((array) config('app.supported_locales') as $locale) {
                $this->client->setMyCommands(self::commandsFor($locale), $locale);
            }
        } catch (TelegramException $exception) {
            throw new TelegramBotException('telegram_error', apiCode: $exception->code());
        }

        SettingsAudit::record(SettingsArea::TelegramBot, self::AUDIT_KEY, AuditAction::Updated, $user, $ip);
    }
}
