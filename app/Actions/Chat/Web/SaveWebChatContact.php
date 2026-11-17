<?php

namespace App\Actions\Chat\Web;

use App\Enums\ChatAuthor;
use App\Enums\ChatMode;
use App\Models\WebChat;
use App\Support\Chat\WebChatInput;
use App\Support\Chat\WebChatLimits;
use App\Support\Chat\WebChatSwitch;
use App\Support\Chat\WebChatVersion;
use App\Support\ForeignPhoneLimit;
use Illuminate\Support\Facades\DB;

/**
 * The optional "leave your phone" card after a hand-off (plan 28 C5 §19.3,
 * §19.7 Q3, WC-3, WC-9). The widget validates the phone with the project
 * rules first (PhoneNumber(public: true), Phone::normalizeAny) and passes
 * the normalized E.164 number; this action checks its shape again.
 *
 * Limits before the transaction: per chat chatbot.web.contact_per_hour,
 * then the site's foreign-phone cap (ForeignPhoneLimit, last). Under the
 * row lock: only a handed-off chat that is not banned, closed or expired,
 * and only once (no phone stored yet). The name is cleaned like visitor
 * text (no control characters, one line, 64 characters); the phone is kept
 * on the row only (staff see it as "given by the visitor, not verified"),
 * never in a message text or an alert, and never matched to a customer.
 * Staff get a system line without the number.
 */
class SaveWebChatContact
{
    public const SAVED = 'saved';

    public const LIMITED = 'limited';

    public const FOREIGN_LIMIT = 'foreign_limit';

    public const REFUSED = 'refused';

    public const NAME_MAX = 64;

    /**
     * @return self::* what happened
     */
    public function handle(WebChat $chat, ?string $name, string $phone): string
    {
        if (! WebChatSwitch::isOpen() || preg_match('/^\+[1-9]\d{7,14}$/D', $phone) !== 1) {
            return self::REFUSED;
        }

        if (! WebChatLimits::contact($chat)) {
            return self::LIMITED;
        }

        if (ForeignPhoneLimit::exceeded($phone)) {
            return self::FOREIGN_LIMIT;
        }

        $name = WebChatInput::clean($name);
        $name = $name === null ? null : (mb_substr(trim((string) preg_replace('/\s+/u', ' ', $name)), 0, self::NAME_MAX) ?: null);

        return DB::transaction(function () use ($chat, $name, $phone): string {
            $locked = WebChat::query()->whereKey($chat->id)->lockForUpdate()->first();

            if ($locked === null || $locked->isBanned() || $locked->isClosed() || $locked->isExpired()
                || $locked->mode !== ChatMode::Manager || $locked->handoff_at === null || $locked->phone !== null) {
                return self::REFUSED;
            }

            $locked->forceFill(['name' => $locked->name ?? $name, 'phone' => $phone]);
            $locked->messages()->create(['author' => ChatAuthor::System, 'text' => __('telegram_inbox.web.contact_line', [], 'uz')]);
            $locked->save();

            WebChatVersion::bump($locked->id);

            return self::SAVED;
        });
    }
}
