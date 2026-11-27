<?php

namespace App\Support\Office;

use App\Casts\EncryptedText;
use App\Enums\BusinessAuthor;
use App\Enums\TelegramMessageKind;
use App\Models\BusinessChat;
use App\Models\BusinessMessage;
use App\Support\Chat\ChatRedactor;
use Illuminate\Support\Collection;

/**
 * The transcript of a client chat as the model sees it (plan 41 §6.2, VO-6):
 * the newest office.transcript.messages messages, oldest first, within
 * office.transcript.chars characters (the oldest lines are dropped first),
 * one line per message "[client 10:02] text" / "[manager 10:05] text".
 *
 * Every text and file name goes through ChatRedactor (phones, cards, INN,
 * emails, links, long numbers masked); media become placeholders
 * ("[photo]", "[document: name.pdf]"), a contact or a location only
 * "[contact]" / "[location]". Never a name, username, Telegram id, phone,
 * CRM customer or company, or a staff name. Deleted and unreadable
 * messages are left out. The ids and edit stamps of every message in the
 * window (deleted ones included) feed the input hash.
 */
final readonly class BusinessTranscript
{
    /**
     * @param  list<string>  $lines
     * @param  list<array{int, int|null, bool}>  $stamps  [id, edited_at timestamp, deleted]
     * @param  list<array{int, string}>  $clientLines  [message id, the client's redacted words] of the lines in the window
     */
    public function __construct(
        public array $lines,
        public array $stamps,
        public ?int $upToMessageId,
        public array $clientLines = [],
    ) {}

    public static function build(BusinessChat $chat): self
    {
        /** @var Collection<int, BusinessMessage> $rows */
        $rows = $chat->messages()
            ->orderByDesc('id')
            ->limit(max(1, (int) config('office.transcript.messages', 40)))
            ->get(['id', 'business_chat_id', 'author', 'kind', 'text', 'media', 'sent_at', 'edited_at', 'deleted_at', 'created_at'])
            ->reverse()
            ->values();

        $lines = [];
        $stamps = [];
        $clientLines = [];

        foreach ($rows as $message) {
            $stamps[] = [$message->id, $message->edited_at?->getTimestamp(), $message->deleted_at !== null];

            $line = self::line($message);

            if ($line !== null) {
                $lines[] = $line;

                if ($message->author === BusinessAuthor::Client) {
                    $clientLines[] = [$message->id, (string) preg_replace('/^\[client [^\]]*\]\s*/', '', $line)];
                }
            }
        }

        return new self(self::withinBudget($lines), $stamps, $rows->last()?->id, $clientLines);
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function text(): string
    {
        return implode("\n", $this->lines);
    }

    /**
     * The client's own words only (site-knowledge search), redacted.
     */
    public function clientText(int $lines = 6): string
    {
        $client = array_values(array_filter($this->lines, fn (string $line): bool => str_starts_with($line, '[client ')));

        return implode(' ', array_map(fn (string $line): string => (string) preg_replace('/^\[client [^\]]*\]\s*/', '', $line), array_slice($client, -$lines)));
    }

    /**
     * The client's words written after a message id (the previous analysis), the last few only: what the
     * escalation backstops scan (plan 46 ED46-3), so an old message never flags or blocks a new draft.
     */
    public function clientTextSince(?int $afterMessageId, int $max = 6): string
    {
        $recent = array_filter($this->clientLines, fn (array $line): bool => $afterMessageId === null || $line[0] > $afterMessageId);

        return implode(' ', array_column(array_slice(array_values($recent), -$max), 1));
    }

    private static function line(BusinessMessage $message): ?string
    {
        if ($message->deleted_at !== null) {
            return null;
        }

        $parts = [];
        $placeholder = self::placeholder($message);

        if ($placeholder !== null) {
            $parts[] = $placeholder;
        }

        if (EncryptedText::isReadable($message->text)) {
            $text = ChatRedactor::clean($message->text, (int) config('office.transcript.message_chars', 1000));

            if ($text !== '') {
                $parts[] = $text;
            }
        }

        if ($parts === []) {
            return null;
        }

        $who = $message->author === BusinessAuthor::Client ? 'client' : 'manager';
        $time = ($message->sent_at ?? $message->created_at)?->copy()->setTimezone((string) config('app.timezone'))->format('H:i') ?? '--:--';

        return '['.$who.' '.$time.'] '.OfficePrompt::defang(implode(' ', $parts));
    }

    private static function placeholder(BusinessMessage $message): ?string
    {
        $media = is_array($message->media) ? $message->media : [];

        if (isset($media['venue'])) {
            return '[location]';
        }

        return match ($message->kind) {
            TelegramMessageKind::Text => null,
            TelegramMessageKind::Document => isset($media['file_name']) && is_string($media['file_name']) && ($name = ChatRedactor::clean($media['file_name'], 80)) !== ''
                ? '[document: '.$name.']' : '[document]',
            TelegramMessageKind::Contact => '[contact]',
            TelegramMessageKind::Location => '[location]',
            TelegramMessageKind::VideoNote => '[video]',
            default => '['.$message->kind->value.']',
        };
    }

    /**
     * Drop the oldest lines until the transcript fits the character budget.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private static function withinBudget(array $lines): array
    {
        $budget = max(500, (int) config('office.transcript.chars', 8000));

        while ($lines !== [] && mb_strlen(implode("\n", $lines)) > $budget) {
            array_shift($lines);
        }

        return $lines;
    }
}
