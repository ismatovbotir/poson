<?php

namespace App\Support\Office;

use App\Casts\EncryptedText;
use App\Enums\GroupMemberRole;
use App\Enums\TelegramMessageKind;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupMember;
use App\Models\TelegramGroupMessage;
use App\Support\Chat\ChatRedactor;
use Illuminate\Support\Collection;

/**
 * The transcript of a tracked group as the model sees it (plan 44 §2, OG-5):
 * structured data, a JSON array of {label, time, text}, never inline
 * "[client A]" text, so nothing a member writes can pose as a label.
 *
 *  - the newest office.transcript.messages messages, oldest first, within
 *    office.transcript.chars characters of JSON (the oldest are dropped);
 *  - labels are random per run ("client A", "staff 1", "unknown B"): the
 *    members are shuffled before the letters / numbers are given out, and a
 *    label never comes from a name. Bots are left out;
 *  - every text and file name: the group title and the members' names and
 *    usernames masked, then ChatRedactor (@mentions, t.me and other links,
 *    phones, cards, INN, emails and every 5+ digit run masked), then
 *    defanged. Media become placeholders. Never the title, a Telegram id, a
 *    name, a username, a phone or a customer;
 *  - the label map (label → member id and role), the message ids used (M2
 *    re-check before saving) and the edit stamps (input hash) stay in PHP.
 */
final readonly class GroupTranscript
{
    /**
     * @param  list<array{label: string, time: string, text: string}>  $entries
     * @param  array<string, array{member_id: int, role: GroupMemberRole}>  $labels
     * @param  list<int>  $messageIds
     * @param  list<array{int, int|null}>  $stamps  [id, edited_at timestamp]
     */
    public function __construct(
        public array $entries,
        public array $labels,
        public array $messageIds,
        public array $stamps,
        public ?int $upToMessageId,
    ) {}

    public static function build(TelegramGroup $group): self
    {
        /** @var Collection<int, TelegramGroupMessage> $rows */
        $rows = TelegramGroupMessage::query()
            ->where('telegram_group_id', $group->id)
            ->with('member:id,telegram_group_id,role,is_bot,first_name,username')
            ->orderByDesc('id')
            ->limit(max(1, (int) config('office.transcript.messages', 40)))
            ->get(['id', 'telegram_group_id', 'member_id', 'kind', 'text', 'media', 'sent_at', 'edited_at', 'created_at'])
            ->reverse()
            ->values();

        $rows = $rows->filter(fn (TelegramGroupMessage $message): bool => $message->member instanceof TelegramGroupMember
            && ! $message->member->is_bot && $message->member->role !== GroupMemberRole::Bot)->values();

        $members = $rows->pluck('member')->unique('id')->values();
        $labelOf = self::labels($members);
        $secrets = self::secrets($group);

        $entries = [];

        foreach ($rows as $message) {
            $text = self::text($message, $secrets);

            if ($text === null) {
                continue;
            }

            $entries[] = [
                'message_id' => $message->id,
                'edited' => $message->edited_at?->getTimestamp(),
                'label' => $labelOf[$message->member_id],
                'time' => ($message->sent_at ?? $message->created_at)?->copy()->setTimezone((string) config('app.timezone'))->format('H:i') ?? '--:--',
                'text' => $text,
            ];
        }

        $entries = self::withinBudget($entries);
        $used = array_values(array_unique(array_column($entries, 'label')));
        $map = [];

        foreach ($members as $member) {
            if (in_array($labelOf[$member->id], $used, true)) {
                $map[$labelOf[$member->id]] = ['member_id' => $member->id, 'role' => $member->role];
            }
        }

        return new self(
            array_map(fn (array $entry): array => ['label' => $entry['label'], 'time' => $entry['time'], 'text' => $entry['text']], $entries),
            $map,
            array_map(fn (array $entry): int => $entry['message_id'], $entries),
            array_map(fn (array $entry): array => [$entry['message_id'], $entry['edited']], $entries),
            $entries === [] ? null : max(array_column($entries, 'message_id')),
        );
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * The data block: a JSON array of {label, time, text}.
     */
    public function json(): string
    {
        return (string) json_encode($this->entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * The member ids whose messages are in the transcript.
     *
     * @return list<int>
     */
    public function memberIds(): array
    {
        $ids = array_values(array_unique(array_column($this->labels, 'member_id')));
        sort($ids);

        return $ids;
    }

    /**
     * The words of the given members who are clients now (role at build time), written after a message id
     * (the previous analysis), the last few only (plan 46 escalation backstops).
     *
     * @param  list<int>  $memberIds
     */
    public function clientText(array $memberIds, ?int $afterMessageId = null, int $max = 6): string
    {
        $labels = array_keys(array_filter($this->labels, fn (array $entry): bool => $entry['role'] === GroupMemberRole::Client && in_array($entry['member_id'], $memberIds, true)));
        $texts = [];

        foreach ($this->entries as $index => $entry) {
            if (in_array($entry['label'], $labels, true) && ($afterMessageId === null || ($this->messageIds[$index] ?? 0) > $afterMessageId)) {
                $texts[] = $entry['text'];
            }
        }

        return implode(' ', array_slice($texts, -$max));
    }

    /**
     * The member a label of this run stands for, only when that member is a client (OG-5).
     */
    public function clientMemberId(mixed $label): ?int
    {
        if (! is_string($label) || ! isset($this->labels[$label])) {
            return null;
        }

        return $this->labels[$label]['role'] === GroupMemberRole::Client ? $this->labels[$label]['member_id'] : null;
    }

    /**
     * The labels of this run for some members.
     *
     * @param  list<int>  $memberIds
     * @return list<string>
     */
    public function labelsFor(array $memberIds): array
    {
        return array_values(array_keys(array_filter($this->labels, fn (array $entry): bool => in_array($entry['member_id'], $memberIds, true))));
    }

    /**
     * Random labels per run: members shuffled, then "client A, B…", "staff 1, 2…", "unknown A, B…".
     *
     * @param  Collection<int, TelegramGroupMember>  $members
     * @return array<int, string> member id → label
     */
    private static function labels(Collection $members): array
    {
        $counters = ['client' => 0, 'staff' => 0, 'unknown' => 0];
        $labels = [];

        foreach ($members->shuffle() as $member) {
            $kind = match ($member->role) {
                GroupMemberRole::Client => 'client',
                GroupMemberRole::Employee => 'staff',
                default => 'unknown',
            };

            $index = $counters[$kind]++;
            $labels[$member->id] = $kind.' '.($kind === 'staff' ? (string) ($index + 1) : self::letters($index));
        }

        return $labels;
    }

    private static function letters(int $index): string
    {
        $letters = '';

        do {
            $letters = chr(65 + $index % 26).$letters;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);

        return $letters;
    }

    /**
     * Masked in every text (whole words only, see mask()): the group title and every member's name and
     * username (3+ characters) as a whole, plus each of their name / username tokens of 3+ characters
     * ("Ali Valiyev" → "Ali", "Valiyev"; "fake_ali" → "fake", "ali"), plus a 2-letter name ("Bo").
     *
     * @return list<string>
     */
    private static function secrets(TelegramGroup $group): array
    {
        $values = [];

        foreach ([$group->title, $group->username] as $value) {
            if (is_string($value) && mb_strlen(trim($value)) >= 3) {
                $values[] = trim($value);
            }
        }

        foreach (TelegramGroupMember::query()->where('telegram_group_id', $group->id)->get(['id', 'first_name', 'username']) as $member) {
            foreach ([$member->first_name, $member->username] as $value) {
                $value = is_string($value) ? trim($value) : '';
                $length = mb_strlen($value);

                if ($length >= 3 || ($length === 2 && preg_match('/^\p{L}{2}$/u', $value) === 1)) {
                    $values[] = $value;
                }

                foreach (preg_split('/[^\p{L}\p{N}]+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                    if (mb_strlen($token) >= 3) {
                        $values[] = $token;
                    }
                }
            }
        }

        usort($values, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return array_values(array_unique($values));
    }

    /**
     * @param  list<string>  $secrets
     */
    private static function mask(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            $text = (string) preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($secret, '/').'(?![\p{L}\p{N}])/iu', ChatRedactor::MASK, $text);
        }

        return $text;
    }

    /**
     * @param  list<string>  $secrets
     */
    private static function text(TelegramGroupMessage $message, array $secrets): ?string
    {
        $parts = [];
        $placeholder = self::placeholder($message, $secrets);

        if ($placeholder !== null) {
            $parts[] = $placeholder;
        }

        if (EncryptedText::isReadable($message->text)) {
            $text = ChatRedactor::clean(self::mask((string) $message->text, $secrets), (int) config('office.transcript.message_chars', 1000));

            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return $parts === [] ? null : OfficePrompt::defang(implode(' ', $parts));
    }

    /**
     * @param  list<string>  $secrets
     */
    private static function placeholder(TelegramGroupMessage $message, array $secrets): ?string
    {
        $media = is_array($message->media) ? $message->media : [];

        if (isset($media['venue'])) {
            return '[location]';
        }

        return match ($message->kind) {
            TelegramMessageKind::Text => null,
            TelegramMessageKind::Document => isset($media['file_name']) && is_string($media['file_name']) && ($name = ChatRedactor::clean(self::mask($media['file_name'], $secrets), 80)) !== ''
                ? '[document: '.$name.']' : '[document]',
            TelegramMessageKind::Contact => '[contact]',
            TelegramMessageKind::Location => '[location]',
            TelegramMessageKind::VideoNote => '[video]',
            default => '['.$message->kind->value.']',
        };
    }

    /**
     * Drop the oldest entries until the JSON fits the character budget.
     *
     * @param  list<array{message_id: int, edited: int|null, label: string, time: string, text: string}>  $entries
     * @return list<array{message_id: int, edited: int|null, label: string, time: string, text: string}>
     */
    private static function withinBudget(array $entries): array
    {
        $budget = max(500, (int) config('office.transcript.chars', 8000));
        $size = fn (array $rows): int => mb_strlen((string) json_encode(array_map(fn (array $row): array => [$row['label'], $row['time'], $row['text']], $rows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)) + 30 * count($rows);

        while ($entries !== [] && $size($entries) > $budget) {
            array_shift($entries);
        }

        return $entries;
    }
}
