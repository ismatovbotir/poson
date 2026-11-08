<?php

namespace App\Support\Chat\Adapters;

use App\Support\Chat\ChatReply;

/**
 * Turns a ChatReply into what the Telegram bot sends and reads back (plan 28
 * §2, CB-3, CB-5). Pure: no database, no HTTP.
 *
 * Output is plain text; the sending job escapes it with e() and sends it with
 * parse_mode HTML, so a "<b>" typed by an admin or a visitor is shown, not run.
 *
 * Callback data (the whole set, matched with /D so a trailing newline fails):
 *   chat:r:N      another rule by id; accepted only for an active Telegram rule
 *                 that is in the menu or was offered in the last bot answer
 *   chat:b:G:I    button I (0-9) of the answer generation G (0-99), stored server-side
 *                 in telegram_users.bot_dialog (FAQ questions); never an id from the
 *                 client, and ignored when G is not the current generation
 *   chat:menu     show the topic menu
 *   chat:handoff  talk to a manager
 *   chat:cancel   leave a dialog and show the menu
 */
final class TelegramAdapter
{
    public const CALLBACK_BODY = 'chat:(?:r:\d{1,9}|menu|handoff|cancel|b:\d{1,2}:\d)';

    public const CALLBACK_PATTERN = '/^'.self::CALLBACK_BODY.'$/D';

    public const MAX_TEXT_UNITS = 4000;

    public const LABEL_MAX = 60;

    /**
     * @return array{type: string, id?: int, gen?: int, index?: int}|null null: not chat callback data
     */
    public static function parseCallback(?string $data): ?array
    {
        if ($data === null || preg_match(self::CALLBACK_PATTERN, $data) !== 1) {
            return null;
        }

        $parts = explode(':', $data);

        return match ($parts[1]) {
            'r' => ['type' => 'rule', 'id' => (int) $parts[2]],
            'b' => ['type' => 'button', 'gen' => (int) $parts[2], 'index' => (int) $parts[3]],
            'menu' => ['type' => 'menu'],
            'handoff' => ['type' => 'handoff'],
            default => ['type' => 'cancel'],
        };
    }

    /**
     * @return array{
     *     text: string,
     *     inline: list<list<array{text: string, callback_data?: string, url?: string}>>,
     *     dialog: array{rules: list<int>, buttons: array<int, array{t: string, id: int}>, gen: int, at: int}
     * }
     */
    public function render(ChatReply $reply, int $generation = 0): array
    {
        $rows = [];
        $rules = [];
        $buttons = [];

        foreach ($reply->buttons as $button) {
            $label = mb_substr(trim($button['label']), 0, self::LABEL_MAX);

            if ($label === '') {
                continue;
            }

            switch ($button['type']) {
                case 'rule':
                    $rules[] = (int) $button['id'];
                    $rows[] = [['text' => $label, 'callback_data' => 'chat:r:'.(int) $button['id']]];
                    break;
                case 'faq':
                    if (count($buttons) < 10) {
                        $index = count($buttons);
                        $buttons[$index] = ['t' => 'faq', 'id' => (int) $button['id']];
                        $rows[] = [['text' => $label, 'callback_data' => 'chat:b:'.$generation.':'.$index]];
                    }
                    break;
                case 'url':
                    $rows[] = [['text' => $label, 'url' => (string) $button['url']]];
                    break;
                case 'handoff':
                    $rows[] = [['text' => $label, 'callback_data' => 'chat:handoff']];
                    break;
                case 'menu':
                    $rows[] = [['text' => $label, 'callback_data' => 'chat:menu']];
                    break;
            }
        }

        return [
            'text' => $this->text($reply->text),
            'inline' => $rows,
            'dialog' => ['rules' => array_values(array_unique($rules)), 'buttons' => $buttons, 'gen' => $generation, 'at' => now()->unix()],
        ];
    }

    /**
     * Reply markup of the Telegram send call: an inline keyboard, or null
     * (the persistent main menu keyboard stays as it is).
     *
     * @param  list<list<array{text: string, callback_data?: string, url?: string}>>  $inline
     * @return array{inline_keyboard: list<list<array{text: string, callback_data?: string, url?: string}>>}|null
     */
    public static function markup(array $inline): ?array
    {
        return $inline === [] ? null : ['inline_keyboard' => $inline];
    }

    /**
     * Plain text for Telegram: control characters except line breaks removed,
     * cut to the message limit.
     */
    private function text(string $text): string
    {
        $text = mb_scrub(str_replace(["\r\n", "\r"], "\n", $text));
        $clean = '';

        foreach (mb_str_split($text) as $char) {
            $code = mb_ord($char);

            if ($code === 10 || ($code >= 32 && $code !== 127 && ! ($code >= 0x80 && $code <= 0x9F))) {
                $clean .= $char;
            }
        }

        return trim(mb_substr($clean, 0, self::MAX_TEXT_UNITS));
    }
}
