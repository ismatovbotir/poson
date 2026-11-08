<?php

namespace App\Actions\Telegram;

use App\Actions\Chat\HandleChatInput;
use App\Enums\ChatMode;
use App\Enums\TelegramMessageKind;
use App\Enums\TelegramUserState;
use App\Jobs\AnswerBotCallback;
use App\Jobs\SendBotPrompt;
use App\Models\TelegramUser;
use App\Support\Chat\ChatbotSwitch;
use App\Support\Phone;
use App\Support\Telegram\Bot\BotKeyboards;
use App\Support\Telegram\Bot\BotText;
use App\Support\Telegram\Bot\UpdateParser;

/**
 * The customer bot conversation state machine (plan 14 v2 §7). Runs inside
 * HandleTelegramUpdate's transaction with the telegram_users row locked.
 *
 * States: language → contact → name → company → inn → registered.
 *  - /start: registered → main menu; otherwise the current step again.
 *  - /language: the language picker (any state); /help: help text plus the
 *    current step (or the menu).
 *  - Callbacks: only BotKeyboards::CALLBACK_PATTERN data, and only in the
 *    state it belongs to (lang:* any state); anything else is answered and
 *    ignored, without a state change (condition 15).
 *  - Contact: only in state contact, only the sender's own contact
 *    (contact.user_id === from.id), never a forwarded one; the vcard is
 *    never read; digits → Phone::normalizeAny('+'.digits) (condition 5).
 *    consent_at is set when the contact is accepted.
 *  - Name 2–120, company ≤ 160 (both BotText::clean: control, bidi and
 *    zero-width characters removed), INN only after a company, exactly 9
 *    digits. Company and INN can be skipped.
 *  - Free text and media before registration are never stored: the user
 *    gets a re-prompt. After registration they go to the inbox
 *    (StoreInboundMessage). Main menu labels are commands, never stored.
 *
 * Every reply is a queued SendBotPrompt (after commit); nothing is sent
 * from here. Replies never depend on CRM data (condition 6).
 */
class AdvanceRegistration
{
    public const NAME_MIN = 2;

    public const NAME_MAX = 120;

    public const COMPANY_MAX = 160;

    public function __construct(
        private readonly RegisterTelegramUser $register,
        private readonly RouteRegisteredMessage $router,
        private readonly HandleChatInput $chat,
    ) {}

    public function handle(TelegramUser $user, UpdateParser $update): void
    {
        if ($update->type === UpdateParser::TYPE_CALLBACK) {
            $this->callback($user, $update);

            return;
        }

        $command = $this->command($update);

        if ($command !== null) {
            $this->runCommand($user, $command);

            return;
        }

        if ($user->state === TelegramUserState::Registered) {
            $this->registered($user, $update);

            return;
        }

        match ($user->state) {
            TelegramUserState::Language => $this->currentStep($user),
            TelegramUserState::Contact => $this->contact($user, $update),
            TelegramUserState::Name => $this->name($user, $update),
            TelegramUserState::Company => $this->company($user, $update),
            TelegramUserState::Inn => $this->inn($user, $update),
        };
    }

    /**
     * "/start", "/start payload", "/start@PosonBot" → "start"; menu button
     * labels → "menu:<button>"; any other "/…" → "unknown"; null for plain text.
     */
    private function command(UpdateParser $update): ?string
    {
        if ($update->kind !== TelegramMessageKind::Text || $update->text === null) {
            return null;
        }

        if (preg_match('/^\/(start|language|help)(?:@\w+)?(?:\s|$)/i', $update->text, $matches) === 1) {
            return strtolower($matches[1]);
        }

        if (str_starts_with($update->text, '/')) {
            return 'unknown';
        }

        $button = BotKeyboards::menuButton($update->text);

        return $button !== null ? 'menu:'.$button : null;
    }

    private function runCommand(TelegramUser $user, string $command): void
    {
        $registered = $user->state === TelegramUserState::Registered;

        match (true) {
            $command === 'start' && $registered => $this->mainMenu($user),
            $command === 'start' => $this->currentStep($user),
            $command === 'language', $command === 'menu:language' && $registered => $this->reply($user, ['language.prompt'], BotKeyboards::LANGUAGE),
            $command === 'help' => $this->currentStep($user, prefix: ['help']),
            $command === 'menu:contact_manager' && $registered => $this->contactManager($user),
            $command === 'menu:faq' && $registered => $this->chat->menuKey($user, 'faq'),
            $command === 'menu:order_status' && $registered => $this->chat->menuKey($user, 'order_status'),
            $command === 'menu:catalog' && $registered => $this->reply($user, ['menu.catalog_reply'], BotKeyboards::MENU),
            // A menu label typed before registration is just text: re-prompt.
            default => $this->currentStep($user, prefix: $this->continuePrefix($user)),
        };
    }

    private function callback(TelegramUser $user, UpdateParser $update): void
    {
        $data = $update->callbackData;
        $state = $user->state;
        $allowed = BotKeyboards::isAllowedCallback($data) && match (true) {
            str_starts_with((string) $data, 'lang:') => true,
            str_starts_with((string) $data, 'skip:company') => $state === TelegramUserState::Company,
            str_starts_with((string) $data, 'skip:inn') => $state === TelegramUserState::Inn,
            str_starts_with((string) $data, 'name:') => $state === TelegramUserState::Name,
            str_starts_with((string) $data, 'chat:') => $state === TelegramUserState::Registered && ChatbotSwitch::isEnabled(),
            default => false,
        };

        if (! $allowed) {
            if ($update->callbackQueryId !== null) {
                dispatch(new AnswerBotCallback($update->callbackQueryId));
            }

            return;
        }

        $answer = ['callbackQueryId' => $update->callbackQueryId, 'clearMarkupMessageId' => $update->messageId];
        [$action, $value] = explode(':', (string) $data, 2);

        match ($action) {
            'lang' => $this->chooseLanguage($user, $value, $answer),
            'skip' => $value === 'company'
                ? $this->finish($user->forceFill(['declared_company' => null, 'declared_inn' => null]), $answer)
                : $this->finish($user->forceFill(['declared_inn' => null]), $answer),
            'chat' => $this->chat->callback($user, $update),
            'name' => $value === 'yes' && $user->name !== null
                ? $this->toCompany($user, $answer)
                : $this->askName($user->forceFill(['name' => null]), $answer),
        };
    }

    /**
     * @param  array{callbackQueryId: ?string, clearMarkupMessageId: ?int}  $answer
     */
    private function chooseLanguage(TelegramUser $user, string $locale, array $answer): void
    {
        $first = $user->state === TelegramUserState::Language;
        $user->locale = $locale;

        if ($first) {
            $user->state = TelegramUserState::Contact;
        }

        $user->save();

        if ($user->state === TelegramUserState::Registered) {
            $this->reply($user, ['language.changed'], BotKeyboards::MENU, $answer);

            return;
        }

        $this->currentStep($user, prefix: $first ? [] : ['language.changed'], answer: $answer);
    }

    private function contact(TelegramUser $user, UpdateParser $update): void
    {
        if ($update->kind !== TelegramMessageKind::Contact) {
            $this->reply($user, ['contact.use_button'], BotKeyboards::CONTACT);

            return;
        }

        if ($update->isForwarded || $update->contactUserId === null || $update->contactUserId !== $update->fromId) {
            $this->reply($user, ['contact.not_own'], BotKeyboards::CONTACT);

            return;
        }

        $digits = (string) preg_replace('/\D+/', '', (string) $update->contactPhone);
        $phone = $digits !== '' ? Phone::normalizeAny('+'.$digits) : null;

        if ($phone === null) {
            $this->reply($user, ['contact.invalid'], BotKeyboards::CONTACT);

            return;
        }

        $candidate = BotText::clean(trim(($user->first_name ?? '').' '.($user->last_name ?? '')));

        $user->forceFill([
            'phone' => $phone,
            'consent_at' => now(),
            'state' => TelegramUserState::Name,
            'name' => $this->validName($candidate) ? $candidate : null,
        ])->save();

        $this->currentStep($user);
    }

    private function name(TelegramUser $user, UpdateParser $update): void
    {
        if ($update->kind !== TelegramMessageKind::Text) {
            $this->currentStep($user, prefix: ['registration.continue']);

            return;
        }

        $name = BotText::clean($update->text);

        if (! $this->validName($name)) {
            $this->reply($user, ['name.invalid']);

            return;
        }

        $user->name = $name;
        $this->toCompany($user);
    }

    private function company(TelegramUser $user, UpdateParser $update): void
    {
        if ($update->kind !== TelegramMessageKind::Text) {
            $this->currentStep($user, prefix: ['registration.continue']);

            return;
        }

        $company = BotText::clean($update->text);

        if ($company === '' || mb_strlen($company) > self::COMPANY_MAX) {
            $this->reply($user, ['company.invalid'], BotKeyboards::SKIP_COMPANY);

            return;
        }

        $user->forceFill(['declared_company' => $company, 'state' => TelegramUserState::Inn])->save();
        $this->currentStep($user);
    }

    private function inn(TelegramUser $user, UpdateParser $update): void
    {
        if ($update->kind !== TelegramMessageKind::Text) {
            $this->currentStep($user, prefix: ['registration.continue']);

            return;
        }

        $inn = (string) preg_replace('/\s+/u', '', (string) $update->text);

        if (preg_match('/^\d{9}$/D', $inn) !== 1) {
            $this->reply($user, ['inn.invalid'], BotKeyboards::SKIP_INN);

            return;
        }

        $this->finish($user->forceFill(['declared_inn' => $inn]));
    }

    /**
     * @param  array{callbackQueryId?: ?string, clearMarkupMessageId?: ?int}  $answer
     */
    private function toCompany(TelegramUser $user, array $answer = []): void
    {
        $user->forceFill(['state' => TelegramUserState::Company])->save();
        $this->currentStep($user, answer: $answer);
    }

    /**
     * @param  array{callbackQueryId?: ?string, clearMarkupMessageId?: ?int}  $answer
     */
    private function askName(TelegramUser $user, array $answer = []): void
    {
        $user->save();
        $this->currentStep($user, answer: $answer);
    }

    /**
     * @param  array{callbackQueryId?: ?string, clearMarkupMessageId?: ?int}  $answer
     */
    private function finish(TelegramUser $user, array $answer = []): void
    {
        $user->save();
        $this->register->handle($user);
        $this->reply($user, ['welcome'], BotKeyboards::MENU, $answer);
    }

    /**
     * Registered users' free text and media: RouteRegisteredMessage decides
     * (plan 28). With the chat bot off, or in manager mode, or for media, they
     * belong to the inbox (B4): StoreInboundMessage stores them, raises
     * unread, auto-acks, queues the media forward and the staff alert (all
     * after commit). In bot mode the rule engine answers.
     */
    private function registered(TelegramUser $user, UpdateParser $update): void
    {
        $this->router->handle($user, $update);
    }

    /**
     * /start for a registered user: the main menu. With the chat bot on, a
     * conversation that is not waiting for staff goes back to the bot at
     * once (plan 28 §15); one that is waiting stays with staff (CB-4).
     */
    private function mainMenu(TelegramUser $user): void
    {
        if (ChatbotSwitch::isEnabled() && $user->chat_mode === ChatMode::Manager && ! $user->isWaitingForStaff()) {
            $user->forceFill(['chat_mode' => ChatMode::Bot]);
        }

        $this->reply($user, ['menu.main'], BotKeyboards::MENU);
    }

    /**
     * "Contact a manager": with the chat bot on, the customer's next
     * messages go to the inbox until the conversation returns to the bot.
     */
    private function contactManager(TelegramUser $user): void
    {
        if (ChatbotSwitch::isEnabled()) {
            $this->chat->handoffRequested($user);
        }

        $this->reply($user, ['menu.contact_manager_reply'], BotKeyboards::MENU);
    }

    /**
     * Re-send the prompt of the user's current step (with optional texts before it).
     *
     * @param  list<string>  $prefix
     * @param  array{callbackQueryId?: ?string, clearMarkupMessageId?: ?int}  $answer
     */
    private function currentStep(TelegramUser $user, array $prefix = [], array $answer = []): void
    {
        [$prompt, $keyboard] = match ($user->state) {
            TelegramUserState::Language => ['language.prompt', BotKeyboards::LANGUAGE],
            TelegramUserState::Contact => ['consent.text', BotKeyboards::CONTACT],
            TelegramUserState::Name => $user->name !== null ? ['name.confirm', BotKeyboards::NAME_CONFIRM] : ['name.prompt', BotKeyboards::REMOVE],
            TelegramUserState::Company => ['company.prompt', BotKeyboards::SKIP_COMPANY],
            TelegramUserState::Inn => ['inn.prompt', BotKeyboards::SKIP_INN],
            TelegramUserState::Registered => ['menu.main', BotKeyboards::MENU],
        };

        // Before a language is chosen only help may precede the three-language picker.
        $prefix = $user->state === TelegramUserState::Language ? array_values(array_intersect($prefix, ['help'])) : $prefix;

        $this->reply($user, [...$prefix, $prompt], $keyboard, $answer);
    }

    /**
     * @return list<string>
     */
    private function continuePrefix(TelegramUser $user): array
    {
        return in_array($user->state, [TelegramUserState::Contact, TelegramUserState::Registered], true) ? [] : ['registration.continue'];
    }

    private function validName(string $name): bool
    {
        $length = mb_strlen($name);

        return $length >= self::NAME_MIN && $length <= self::NAME_MAX;
    }

    /**
     * @param  list<string>  $prompts
     * @param  array{callbackQueryId?: ?string, clearMarkupMessageId?: ?int}  $answer
     */
    private function reply(TelegramUser $user, array $prompts, ?string $keyboard = null, array $answer = []): void
    {
        dispatch(new SendBotPrompt(
            $user->id,
            $prompts,
            $keyboard,
            $answer['callbackQueryId'] ?? null,
            $answer['clearMarkupMessageId'] ?? null,
        ));
    }
}
