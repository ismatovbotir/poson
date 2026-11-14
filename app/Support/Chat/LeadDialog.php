<?php

namespace App\Support\Chat;

use App\Enums\ChatDialogStep;
use App\Enums\LeadFlow;
use App\Models\TelegramUser;
use App\Support\Telegram\Bot\BotText;

/**
 * The server-side state of a Telegram lead dialog (plan 28 C4, CB-17), kept
 * in telegram_users.bot_dialog under "flow":
 *
 *   {flow: callback|quote|service, step: details|confirm, started_at: unix,
 *    answers: {details: string, product_id: int|null}}
 *
 * Every read validates the whole shape: an unknown flow or step, a wrong
 * type, an answer over the cap, a start in the future or older than
 * chatbot.leads.dialog_minutes (30) drops the state, so a tampered or stale
 * row is simply no dialog. Answers are Cc/Cf-stripped and at most
 * chatbot.leads.answer_max (500) characters; the state stays well under
 * chatbot.leads.dialog_max_bytes (2 KB) with the stored buttons.
 * Writes only change the model (the caller saves it with the update).
 */
final class LeadDialog
{
    public const KEY = 'flow';

    /**
     * Room left in bot_dialog for the stored buttons and menu rule ids.
     */
    private const BUTTONS_ROOM = 512;

    /**
     * $dropped is set when a flow existed but was invalid or expired (and was cleared).
     *
     * @return array{flow: LeadFlow, step: ChatDialogStep, started_at: int, answers: array{details: string, product_id: int|null}}|null
     */
    public static function current(TelegramUser $user, ?bool &$dropped = null): ?array
    {
        $dropped = false;

        $dialog = is_array($user->bot_dialog) ? $user->bot_dialog : [];

        if (! array_key_exists(self::KEY, $dialog)) {
            return null;
        }

        $state = self::validate($dialog[self::KEY]);

        if ($state === null) {
            $dropped = true;
            self::clear($user);
        }

        return $state;
    }

    public static function start(TelegramUser $user, LeadFlow $flow, ?int $productId = null): void
    {
        self::store($user, [
            'flow' => $flow,
            'step' => ChatDialogStep::Details,
            'started_at' => now()->unix(),
            'answers' => ['details' => '', 'product_id' => $productId],
        ]);
    }

    /**
     * @param  array{flow: LeadFlow, step: ChatDialogStep, started_at: int, answers: array{details: string, product_id: int|null}}  $state
     */
    public static function store(TelegramUser $user, array $state): void
    {
        $details = self::answer($state['answers']['details']);
        $raw = [
            'flow' => $state['flow']->value,
            'step' => $state['step']->value,
            'started_at' => $state['started_at'],
            'answers' => ['details' => $details, 'product_id' => $state['answers']['product_id']],
        ];

        // Multi-byte text (emoji) can be up to four bytes a character: shorten until it fits.
        while (strlen((string) json_encode($raw, JSON_UNESCAPED_UNICODE)) > self::maxBytes() && $raw['answers']['details'] !== '') {
            $raw['answers']['details'] = rtrim(mb_substr($raw['answers']['details'], 0, max(0, mb_strlen($raw['answers']['details']) - 50)));
        }

        $dialog = is_array($user->bot_dialog) ? $user->bot_dialog : [];
        $dialog[self::KEY] = $raw;

        $user->forceFill(['bot_dialog' => $dialog]);
    }

    /**
     * Drop the dialog (menu, /start, cancel, media, hand-off, after the lead).
     */
    public static function clear(TelegramUser $user): void
    {
        $dialog = is_array($user->bot_dialog) ? $user->bot_dialog : null;

        if ($dialog === null || ! array_key_exists(self::KEY, $dialog)) {
            return;
        }

        unset($dialog[self::KEY]);

        $user->forceFill(['bot_dialog' => $dialog === [] ? null : $dialog]);
    }

    /**
     * A customer's answer as the dialog keeps it: control and format
     * characters removed (line breaks kept), cut to answer_max characters.
     */
    public static function answer(mixed $text): string
    {
        if (! is_string($text)) {
            return '';
        }

        $clean = BotText::caption(mb_strcut($text, 0, 8000, 'UTF-8'), PHP_INT_MAX);

        return trim(mb_substr($clean, 0, self::answerMax()));
    }

    /**
     * @return array{flow: LeadFlow, step: ChatDialogStep, started_at: int, answers: array{details: string, product_id: int|null}}|null
     */
    private static function validate(mixed $raw): ?array
    {
        if (! is_array($raw) || ! is_string($raw['flow'] ?? null) || ! is_string($raw['step'] ?? null) || ! is_int($raw['started_at'] ?? null)) {
            return null;
        }

        $flow = LeadFlow::tryFrom($raw['flow']);
        $step = ChatDialogStep::tryFrom($raw['step']);
        $answers = $raw['answers'] ?? null;
        $now = now()->unix();
        $minutes = max(1, (int) config('chatbot.leads.dialog_minutes', 30));

        if ($flow === null || $step === null || ! is_array($answers)) {
            return null;
        }

        if ($raw['started_at'] > $now + 60 || $raw['started_at'] < $now - $minutes * 60) {
            return null;
        }

        $details = $answers['details'] ?? '';
        $productId = $answers['product_id'] ?? null;

        if (! is_string($details) || mb_strlen($details) > self::answerMax() || $details !== self::answer($details)) {
            return null;
        }

        if ($productId !== null && (! is_int($productId) || $productId < 1 || $flow !== LeadFlow::Quote)) {
            return null;
        }

        if ($step === ChatDialogStep::Confirm && $details === '' && ! $flow->detailsOptional() && $productId === null) {
            return null;
        }

        return [
            'flow' => $flow,
            'step' => $step,
            'started_at' => $raw['started_at'],
            'answers' => ['details' => $details, 'product_id' => $productId],
        ];
    }

    private static function answerMax(): int
    {
        return max(1, (int) config('chatbot.leads.answer_max', 500));
    }

    /**
     * The limit is measured as raw UTF-8 and assumes the MySQL json column;
     * SQLite stores \u escapes, which are larger.
     */
    private static function maxBytes(): int
    {
        return max(self::BUTTONS_ROOM * 2, (int) config('chatbot.leads.dialog_max_bytes', 2048)) - self::BUTTONS_ROOM;
    }
}
