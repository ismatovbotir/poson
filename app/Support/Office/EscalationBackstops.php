<?php

namespace App\Support\Office;

use App\Enums\EscalationTrigger;
use App\Rules\BrandSafeText;

/**
 * PHP backstops for the escalation triggers (plan 46 ED46-3): the model may
 * miss or hide a topic, so the client's own words are also scanned in code.
 * Only client-authored text is passed in (never staff text, a summary or the
 * model's answer). Fixed word lists in uz, ru and en; the patterns are code,
 * never built from admin text. discount: the brand banned patterns
 * (BrandSafeText), so any price-negotiation wording counts. legal and
 * payment: flag only.
 */
final class EscalationBackstops
{
    /** Legal and contract wording (matched at a word start, lowercase, apostrophes removed). */
    private const LEGAL = '/(?<!\pL)(?:shartnoma|yurist|advokat|sud(?:ga|da|ya)?\b|da[ʻʼ\']?vo\b|huquqiy|arbitraj|jarima|договор|контракт|юрист|юридическ|адвокат|суд(?:е|а|у|ом)?\b|судебн|иск(?:а|у|ом)?\b|претензи|арбитраж|неустойк|contract|lawyer|legal|lawsuit|court|attorney|arbitration|liabilit)/u';

    /** Payment dispute wording. */
    private const PAYMENT = '/(?<!\pL)(?:pul(?:ni)?\s+qaytar|ikki\s+marta\s+(?:yechil|to.?lan)|to.?lov\s+o.?tmadi|pul\s+yechib|возврат\s+(?:средств|денег)|верните\s+деньги|списал\w*\s+(?:дважды|два\s+раза)|дважды\s+списал|оплата\s+не\s+прошла|деньги\s+списал|refund|charged\s+twice|double\s+charge|money\s+back|payment\s+failed|chargeback)/u';

    /**
     * Triggers the client's words point at (the discount one is "price negotiation" in the UI).
     *
     * @return list<EscalationTrigger>
     */
    public static function detect(string $clientText): array
    {
        if (trim($clientText) === '') {
            return [];
        }

        $found = [];

        if (BrandSafeText::violates($clientText)) {
            $found[] = EscalationTrigger::DiscountRequest;
        }

        $plain = self::plain($clientText);

        if (preg_match(self::LEGAL, $plain) === 1) {
            $found[] = EscalationTrigger::LegalContract;
        }

        if (preg_match(self::PAYMENT, $plain) === 1) {
            $found[] = EscalationTrigger::PaymentDispute;
        }

        return $found;
    }

    /**
     * NFKC, invisible characters removed, lowercase.
     */
    private static function plain(string $text): string
    {
        $normalised = \Normalizer::normalize($text, \Normalizer::FORM_KC);
        $text = is_string($normalised) ? $normalised : $text;

        return mb_strtolower((string) preg_replace('/\p{Cf}/u', '', $text));
    }
}
