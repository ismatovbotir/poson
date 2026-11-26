<?php

namespace App\Support\Office;

use App\Actions\Chat\BuildAiKnowledge;
use App\Enums\AgentRole;
use App\Rules\BrandSafeText;
use App\Support\Chat\AiOutputFilter;
use App\Support\Chat\ChatRedactor;
use App\Support\Telegram\Bot\BotText;

/**
 * Validation of what the router and the agents answered (plan 41 §6.6,
 * VO-7, VO-8), in PHP, before anything is stored. The model controls only
 * the draft, the summary / next step, the topic and these validated lead
 * hints; its role picks the agent and the lead type, never visibility or
 * assignment.
 *
 *  - enums checked, numbers clamped, every text cleaned and cut;
 *  - topic and lead hints: ChatRedactor (links, contacts, long numbers masked);
 *  - summary / next step: a link, contact, card or phone found by
 *    AiOutputFilter is masked with ChatRedactor; still there → dropped;
 *  - draft: cut to office.output.draft, then AiOutputFilter::check() (own
 *    hosts only, no route URLs in phase 1, no amounts) and BrandSafeText:
 *    any violation → blocked (never stored, never shown).
 */
final class AnalysisOutput
{
    /**
     * Violations that are masked (not dropped) in the summary and next step.
     */
    private const MASKABLE = ['link', 'contact', 'card', 'phone'];

    public function __construct(private readonly AiOutputFilter $filter) {}

    public static function make(): self
    {
        return new self(app(BuildAiKnowledge::class)->outputFilter([], []));
    }

    /**
     * @return array{role: AgentRole, topic: ?string, confidence: int, language: ?string, needs_reply: bool, summary: ?string}|null
     */
    public function router(string $text): ?array
    {
        $data = self::decode($text);

        return $data === null ? null : $this->routerData($data);
    }

    /**
     * One decoded router object (a business chat answer, or one thread of a group answer).
     *
     * @param  array<string, mixed>  $data
     * @return array{role: AgentRole, topic: ?string, confidence: int, language: ?string, needs_reply: bool, summary: ?string}|null
     */
    public function routerData(array $data): ?array
    {
        $role = is_string($data['role'] ?? null) ? AgentRole::tryFrom($data['role']) : null;

        if ($role === null || ! in_array($role, AgentRole::routable(), true)) {
            return null;
        }

        $language = is_string($data['language'] ?? null) && in_array($data['language'], OfficePrompt::LANGUAGES, true) ? $data['language'] : null;

        return [
            'role' => $role,
            'topic' => $this->topic($data['topic'] ?? null),
            'confidence' => max(0, min(100, (int) ($data['confidence'] ?? 0))),
            'language' => $language,
            'needs_reply' => ($data['needs_reply'] ?? false) === true,
            'summary' => $this->note($data['summary'] ?? null, (int) config('office.output.router_summary', 300)),
        ];
    }

    /**
     * @return array{summary: ?string, next_step: ?string, draft: ?string, draft_blocked: bool, lead: array{intent: ?string, products: list<string>, device_brand: ?string, device_model: ?string, urgency: ?string}}|null
     */
    public function agent(string $text): ?array
    {
        $data = self::decode($text);

        if ($data === null) {
            return null;
        }

        $draft = $this->draft($data['draft_reply'] ?? null);
        $lead = is_array($data['lead'] ?? null) ? $data['lead'] : [];
        $products = [];

        foreach (array_slice(is_array($lead['products'] ?? null) ? $lead['products'] : [], 0, (int) config('office.output.products', 5)) as $product) {
            $clean = $this->plain($product, (int) config('office.output.product_chars', 80));

            if ($clean !== null) {
                $products[] = $clean;
            }
        }

        return [
            'summary' => $this->note($data['summary'] ?? null, (int) config('office.output.summary', 800)),
            'next_step' => $this->note($data['next_step'] ?? null, (int) config('office.output.next_step', 300)),
            'draft' => $draft['text'],
            'draft_blocked' => $draft['blocked'],
            'lead' => [
                'intent' => is_string($lead['intent'] ?? null) && in_array($lead['intent'], OfficePrompt::INTENTS, true) ? $lead['intent'] : null,
                'products' => array_values(array_unique($products)),
                'device_brand' => $this->plain($lead['device_brand'] ?? null, (int) config('office.output.device_brand', 60)),
                'device_model' => $this->plain($lead['device_model'] ?? null, (int) config('office.output.device_model', 60)),
                'urgency' => is_string($lead['urgency'] ?? null) && in_array($lead['urgency'], OfficePrompt::URGENCIES, true) ? $lead['urgency'] : null,
            ],
        ];
    }

    /**
     * The decoded JSON object (a ```json fence around it is tolerated), or null.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $text): ?array
    {
        $text = trim($text);

        if (str_starts_with($text, '```')) {
            $text = trim((string) preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $text));
        }

        $data = json_decode($text, true, 8);

        return is_array($data) && ! array_is_list($data) ? $data : null;
    }

    /**
     * NFKC, then control, format and bidi characters removed (line breaks kept, at most two in a row).
     */
    public static function multiline(string $value): string
    {
        $value = AiOutputFilter::normalize(mb_scrub($value, 'UTF-8'));
        $value = (string) preg_replace('/\r\n?/', "\n", $value);
        $value = (string) preg_replace('/[^\S\n]*[\p{Cf}\p{Zl}\p{Zp}]+|[\x{0000}-\x{0009}\x{000B}-\x{001F}\x{007F}-\x{009F}]/u', '', $value);

        return (string) preg_replace('/\n{3,}/', "\n\n", $value);
    }

    private function topic(mixed $value): ?string
    {
        $topic = $this->plain($value, (int) config('office.output.topic', 120));

        return $topic !== null && ! BrandSafeText::violates($topic, BrandSafeText::CAREERS) ? $topic : null;
    }

    /**
     * One line of plain text: control / bidi characters removed, links and long numbers masked, cut.
     */
    private function plain(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = ChatRedactor::clean(BotText::clean($value), $max);

        return $clean === '' || $clean === ChatRedactor::MASK ? null : $clean;
    }

    /**
     * Summary / next step: masked when the filter finds a link, contact, card or phone; dropped if that stays.
     */
    private function note(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim(mb_substr(self::multiline($value), 0, $max));

        if ($text === '') {
            return null;
        }

        if (in_array($this->filter->checkWithoutMoney($text), self::MASKABLE, true)) {
            $text = ChatRedactor::clean($text, $max);

            if ($text === '' || in_array($this->filter->checkWithoutMoney($text), self::MASKABLE, true)) {
                return null;
            }
        }

        return $text;
    }

    /**
     * @return array{text: ?string, blocked: bool}
     */
    private function draft(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return ['text' => null, 'blocked' => false];
        }

        $draft = trim(mb_substr(self::multiline($value), 0, (int) config('office.output.draft', 1500)));

        if ($draft === '') {
            return ['text' => null, 'blocked' => false];
        }

        if ($this->filter->check($draft) !== null || BrandSafeText::violates($draft, BrandSafeText::CAREERS)) {
            return ['text' => null, 'blocked' => true];
        }

        return ['text' => $draft, 'blocked' => false];
    }
}
