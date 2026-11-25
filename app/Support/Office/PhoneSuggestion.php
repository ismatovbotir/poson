<?php

namespace App\Support\Office;

use App\Casts\EncryptedText;
use App\Enums\BusinessAuthor;
use App\Models\BusinessChat;
use App\Support\Phone;

/**
 * A phone number the client typed in their own messages (plan 41 §6.9,
 * VO-7): found with a local regex, never by the AI, and only ever a
 * SUGGESTION that staff confirm in the "Create lead" form. It is never
 * stored on the chat and never used to match a customer automatically.
 */
final class PhoneSuggestion
{
    public const PATTERN = '/(?<![\d+])(?:\+?998[\s\-().]*)?(?:\(?\d{2}\)?[\s\-.]*)\d{3}[\s\-.]*\d{2}[\s\-.]*\d{2}(?!\d)/';

    public static function forChat(BusinessChat $chat): ?string
    {
        $messages = $chat->messages()->where('author', BusinessAuthor::Client->value)->whereNull('deleted_at')->latest('id')->limit(50)->get(['id', 'text']);

        foreach ($messages as $message) {
            if (! EncryptedText::isReadable($message->text)) {
                continue;
            }

            if (preg_match_all(self::PATTERN, (string) $message->text, $matches) > 0) {
                foreach ($matches[0] as $candidate) {
                    $phone = Phone::normalize($candidate);

                    if ($phone !== null) {
                        return $phone;
                    }
                }
            }
        }

        return null;
    }
}
