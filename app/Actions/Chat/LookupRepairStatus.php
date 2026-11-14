<?php

namespace App\Actions\Chat;

use App\Actions\Chat\Concerns\GuardsStatusLookup;
use App\Enums\RepairStatus;
use App\Models\Repair;
use App\Models\TelegramUser;
use App\Support\Chat\ChatReply;
use App\Support\Chat\LookupNumber;
use App\Support\Telegram\Bot\BotText;
use Illuminate\Database\Eloquent\Builder;

/**
 * Repair status in the Telegram bot (plan 28 C3b, CB-16a to CB-16d, CB-28 to
 * CB-30). Same gate, limits and failure counter as LookupOrderStatus
 * (GuardsStatusLookup); called only by HandleChatInput.
 *
 * Exactly one repairs query after the gate: number (or the id of a button of
 * the customer's own list) AND contact_phone = the verified phone AND (still
 * open OR closed within chatbot.lookup.repair_closed_days, 90). Every miss
 * gets the same reply.
 *
 * The reply holds the status label, the day of the last status change and a
 * device line (type label, brand, model; control/format characters removed,
 * at most DEVICE_MAX characters). Awaiting approval and Ready add a fixed
 * line ("a manager will contact you" / "contact the service centre"). Never
 * the serial, accessories, problem, condition, diagnosis, work, parts,
 * warranty reference, amounts, engineer, company or contact name; the bot
 * never changes a repair.
 */
class LookupRepairStatus
{
    use GuardsStatusLookup;

    public const DEVICE_MAX = 60;

    /**
     * Columns read: nothing else of a repair ever reaches the reply.
     */
    public const COLUMNS = ['id', 'number', 'status', 'status_changed_at', 'device_type', 'device_brand', 'device_model'];

    public function byNumber(TelegramUser $user, string $number, string $locale): ChatReply
    {
        return $this->lookup($user, $locale, fn (Builder $query) => $query->where('number', $number), $number);
    }

    public function byId(TelegramUser $user, int $id, string $locale): ChatReply
    {
        return $this->lookup($user, $locale, fn (Builder $query) => $query->whereKey($id));
    }

    /**
     * The "Repair status" button (CB-29): the customer's last repairs as buttons.
     */
    public function recent(TelegramUser $user, string $locale): ChatReply
    {
        $phone = $this->verifiedPhone($user);

        if ($phone === null) {
            return $this->managerOffer($locale);
        }

        if (! $this->allowLookup($user)) {
            return $this->limited($locale);
        }

        $repairs = $this->scoped($phone)
            ->latest('id')
            ->limit(max(1, min(5, (int) config('chatbot.lookup.recent', 5))))
            ->get(self::COLUMNS);

        if ($repairs->isEmpty()) {
            return new ChatReply(__('chatbot.lookup.repair.none', [], $locale), $this->closingButtons($locale), null, ChatReply::DONE);
        }

        $buttons = $repairs->map(fn (Repair $repair): array => [
            'type' => 'repair',
            'label' => $repair->number.' · '.__('enums.repair_status.'.$repair->status->value, [], $locale),
            'id' => $repair->id,
        ])->all();

        return new ChatReply(__('chatbot.lookup.repair.recent', [], $locale), [...array_values($buttons), ...$this->closingButtons($locale)], null, ChatReply::DONE);
    }

    /**
     * "Scanner · Zebra DS2208": the device type label, brand and model, cleaned and capped.
     */
    public static function deviceLine(Repair $repair, string $locale): string
    {
        $parts = array_filter([
            __('enums.repair_device_type.'.$repair->device_type->value, [], $locale),
            trim(BotText::clean($repair->device_brand).' '.BotText::clean($repair->device_model)),
        ], fn (string $part): bool => $part !== '');

        $line = implode(' · ', $parts);

        return mb_strlen($line) > self::DEVICE_MAX ? rtrim(mb_substr($line, 0, self::DEVICE_MAX - 1)).'…' : $line;
    }

    protected function kind(): string
    {
        return LookupNumber::REPAIR;
    }

    /**
     * @param  callable(Builder<Repair>): mixed  $match
     */
    private function lookup(TelegramUser $user, string $locale, callable $match, ?string $number = null): ChatReply
    {
        $phone = $this->verifiedPhone($user);

        if ($phone === null) {
            return $this->managerOffer($locale);
        }

        if (! $this->allowLookup($user)) {
            return $this->limited($locale);
        }

        $query = $this->scoped($phone);
        $match($query);
        $repair = $query->first(self::COLUMNS);

        if (! $repair instanceof Repair) {
            $this->recordFailure($number);

            return $this->notFound($locale);
        }

        $this->recordSuccess();

        $lines = [
            __('chatbot.lookup.repair.title', ['number' => $repair->number], $locale),
            __('chatbot.lookup.repair.device', ['device' => self::deviceLine($repair, $locale)], $locale),
            __('chatbot.lookup.status', ['status' => __('enums.repair_status.'.$repair->status->value, [], $locale)], $locale),
        ];

        $day = $this->day($repair->status_changed_at, $locale);

        if ($day !== null) {
            $lines[] = __('chatbot.lookup.since', ['day' => $day], $locale);
        }

        $note = match ($repair->status) {
            RepairStatus::AwaitingApproval => __('chatbot.lookup.repair.awaiting_approval', [], $locale),
            RepairStatus::Ready => __('chatbot.lookup.repair.ready', [], $locale),
            default => null,
        };

        if ($note !== null) {
            $lines[] = $note;
        }

        return new ChatReply(implode("\n", $lines), $this->closingButtons($locale), null, ChatReply::DONE);
    }

    /**
     * @return Builder<Repair>
     */
    private function scoped(string $phone): Builder
    {
        $closedSince = now()->subDays(max(1, (int) config('chatbot.lookup.repair_closed_days', 90)));

        return Repair::query()
            ->where('contact_phone', $phone)
            ->where(fn (Builder $query) => $query->whereNull('closed_at')->orWhere('closed_at', '>=', $closedSince));
    }
}
