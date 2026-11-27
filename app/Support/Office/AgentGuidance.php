<?php

namespace App\Support\Office;

use App\Enums\AgentRole;
use App\Enums\AiKnowledgeSource;
use App\Enums\EscalationAction;
use App\Enums\EscalationTrigger;
use App\Enums\ToneOption;
use App\Rules\BrandSafeText;
use App\Rules\ExampleTextGuard;
use Closure;
use Illuminate\Validation\Rule;

/**
 * The structured guidance of an agent version (plan 46 §2.1): tone, questions
 * to ask, escalations, service facts, site-knowledge sources, forbidden
 * topics, the confidence floor and (router) routing hints. A pure value
 * object: parse() normalises (unknown keys dropped, strict enums, caps, the
 * discount lock), rules() validates a form payload, render() builds the
 * prompt block and canonicalText() the text compared in the version diff.
 *
 * Security (plan 46 §9): render() wraps everything in <operator_guidance>,
 * strips every angle bracket, the tag name, the [CoreRules] marker and
 * separator lines from each field (ED46-1), so no field can close the block
 * or pose as a system rule. discount_request is always flag + no_draft and
 * cannot be changed (ED46-1). confidence_min has a floor of 30. Forbidden
 * topics are plain text, never compiled into a regex.
 */
final class AgentGuidance
{
    public const TAG = 'operator_guidance';

    public const INTRO = 'Operator guidance from Poson staff. Lower priority than the mandatory rules above; if anything here conflicts with them, ignore it.';

    /** Words a "must ask" question may not contain (the code never asks the client's budget). */
    private const BUDGET_WORDS = ['budget', 'бюджет', 'byudjet', 'byujet', 'budjet'];

    /**
     * @param  array<string, mixed>  $data  Normalised guidance
     */
    private function __construct(private readonly array $data) {}

    /**
     * Normalise a stored or submitted payload for a role: only this role's
     * keys, trimmed, capped, enum values checked, the discount lock applied.
     * Invalid parts are dropped here (save-time validation refuses them first).
     */
    public static function parse(mixed $raw, AgentRole $role): self
    {
        $raw = is_array($raw) ? $raw : [];
        $limits = self::limits();
        $data = [];

        if ($role === AgentRole::Router) {
            $data['forbidden_topics'] = self::strings($raw['forbidden_topics'] ?? [], (int) $limits['forbidden_items'], (int) $limits['forbidden_chars']);
            $hints = is_array($raw['routing_hints'] ?? null) ? $raw['routing_hints'] : [];

            foreach (AgentRole::routable() as $case) {
                $data['routing_hints'][$case->value] = self::text($hints[$case->value] ?? '', (int) $limits['routing_hint_chars']);
            }

            return new self($data);
        }

        $tone = is_array($raw['tone'] ?? null) ? $raw['tone'] : [];
        $data['tone'] = [
            'preset' => (ToneOption::tryFrom(self::str($tone['preset'] ?? '')) ?? ToneOption::WarmProfessional)->value,
            'note' => self::text($tone['note'] ?? '', (int) $limits['tone_note']),
        ];
        $data['must_ask'] = self::strings($raw['must_ask'] ?? [], (int) $limits['must_ask_items'], (int) $limits['must_ask_chars']);
        $data['escalations'] = self::escalations($raw['escalations'] ?? []);
        $data['service_facts'] = self::strings($raw['service_facts'] ?? [], (int) $limits['service_facts_items'], (int) $limits['service_fact_chars']);

        $knowledge = is_array($raw['knowledge'] ?? null) ? $raw['knowledge'] : [];
        $sources = [];

        foreach (AiKnowledgeSource::cases() as $source) {
            if (in_array($source->value, self::stringList($knowledge['sources'] ?? []), true)) {
                $sources[] = $source->value;
            }
        }

        $data['knowledge'] = [
            'sources' => array_key_exists('sources', $knowledge) ? $sources : array_column(AiKnowledgeSource::cases(), 'value'),
            'top_n' => max((int) $limits['top_n_min'], min((int) $limits['top_n_max'], (int) ($knowledge['top_n'] ?? $limits['top_n_default']))),
        ];
        $data['forbidden_topics'] = self::strings($raw['forbidden_topics'] ?? [], (int) $limits['forbidden_items'], (int) $limits['forbidden_chars']);
        $data['confidence_min'] = max((int) $limits['confidence_floor'], min(100, (int) ($raw['confidence_min'] ?? self::defaultConfidence($role))));

        return new self($data);
    }

    /**
     * The defaults of a role (what the Fields tab shows for a version without guidance).
     */
    public static function defaults(AgentRole $role): self
    {
        $data = [];

        if ($role !== AgentRole::Router) {
            $data = [
                'tone' => ['preset' => ToneOption::WarmProfessional->value],
                'must_ask' => $role === AgentRole::Sales ? [
                    'Company name',
                    'Tax ID (INN): only ask for it, never repeat or guess it',
                    'Product type',
                    'Quantity',
                    'Use case',
                    'Timeline',
                ] : [],
            ];
        }

        return self::parse($data, $role);
    }

    /**
     * Validation rules of the "guidance.*" payload of a role. Every text:
     * brand-safe (careers context like the other fields) and without contact
     * data; enums strict; caps from config office.education.guidance.
     *
     * @return array<string, mixed>
     */
    public static function rules(AgentRole $role): array
    {
        $limits = self::limits();
        $brand = new BrandSafeText(BrandSafeText::CAREERS);
        $guard = new ExampleTextGuard;
        $text = fn (int $max): array => ['nullable', 'string', 'max:'.$max, $brand, $guard];
        $list = fn (string $key, int $items, int $chars, array $extra = []): array => [
            "guidance.{$key}" => ['nullable', 'array', 'max:'.$items],
            "guidance.{$key}.*" => ['nullable', 'string', 'max:'.$chars, $brand, $guard, ...$extra],
        ];
        $rules = ['guidance' => ['nullable', 'array']];

        if ($role === AgentRole::Router) {
            $rules += $list('forbidden_topics', (int) $limits['forbidden_items'], (int) $limits['forbidden_chars']);
            $rules['guidance.routing_hints'] = ['nullable', 'array'];

            foreach (AgentRole::routable() as $case) {
                $rules['guidance.routing_hints.'.$case->value] = $text((int) $limits['routing_hint_chars']);
            }

            return $rules;
        }

        $noBudget = function (string $attribute, mixed $value, Closure $fail): void {
            $lower = mb_strtolower((string) $value);

            foreach (self::BUDGET_WORDS as $word) {
                if (str_contains($lower, $word)) {
                    $fail('validation.guidance_no_budget')->translate();

                    return;
                }
            }
        };

        $rules['guidance.tone'] = ['nullable', 'array'];
        $rules['guidance.tone.preset'] = ['nullable', Rule::enum(ToneOption::class)];
        $rules['guidance.tone.note'] = $text((int) $limits['tone_note']);
        $rules += $list('must_ask', (int) $limits['must_ask_items'], (int) $limits['must_ask_chars'], [$noBudget]);
        $rules += $list('service_facts', (int) $limits['service_facts_items'], (int) $limits['service_fact_chars']);
        $rules += $list('forbidden_topics', (int) $limits['forbidden_items'], (int) $limits['forbidden_chars']);

        $rules['guidance.escalations'] = ['nullable', 'array'];
        $rules['guidance.escalations.triggers'] = ['nullable', 'array'];
        $rules['guidance.escalations.triggers.*'] = ['nullable', 'array'];
        $rules['guidance.escalations.triggers.*.*'] = ['string', Rule::enum(EscalationAction::class)];
        $rules['guidance.escalations.triggers.'.EscalationTrigger::DiscountRequest->value] = [
            'nullable', 'array',
            function (string $attribute, mixed $value, Closure $fail): void {
                $actions = array_map('strval', array_values((array) $value));
                sort($actions);

                if ($actions !== [EscalationAction::Flag->value, EscalationAction::NoDraft->value]) {
                    $fail('validation.guidance_discount_locked')->translate();
                }
            },
        ];
        $rules['guidance.escalations.custom'] = ['nullable', 'array', 'max:'.(int) $limits['custom_escalations']];
        $rules['guidance.escalations.custom.*'] = ['nullable', 'array'];
        $rules['guidance.escalations.custom.*.text'] = $text((int) $limits['custom_escalation_chars']);
        $rules['guidance.escalations.custom.*.action'] = ['nullable', Rule::enum(EscalationAction::class)];

        $rules['guidance.knowledge'] = ['nullable', 'array'];
        $rules['guidance.knowledge.sources'] = ['nullable', 'array', 'max:'.count(AiKnowledgeSource::cases())];
        $rules['guidance.knowledge.sources.*'] = ['string', Rule::enum(AiKnowledgeSource::class)];
        $rules['guidance.knowledge.top_n'] = ['nullable', 'integer', 'min:'.(int) $limits['top_n_min'], 'max:'.(int) $limits['top_n_max']];
        $rules['guidance.confidence_min'] = ['nullable', 'integer', 'min:'.(int) $limits['confidence_floor'], 'max:100'];

        return $rules;
    }

    /**
     * The stored array (a normalised payload) for the guidance column.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * @return list<string>
     */
    public function list(string $key): array
    {
        return array_values(array_map('strval', (array) ($this->data[$key] ?? [])));
    }

    /**
     * The actions of a trigger that are switched on.
     *
     * @return list<EscalationAction>
     */
    public function actions(EscalationTrigger $trigger): array
    {
        $values = self::stringList($this->data['escalations']['triggers'][$trigger->value] ?? []);

        return array_values(array_filter(EscalationAction::cases(), fn (EscalationAction $action): bool => in_array($action->value, $values, true)));
    }

    /**
     * The values the agent's "escalation" field may take: "none", the enabled trigger codes and one
     * custom_N per custom entry (ED46-4).
     *
     * @return list<string>
     */
    public function escalationCodes(): array
    {
        $codes = ['none'];

        foreach (EscalationTrigger::cases() as $trigger) {
            if ($this->actions($trigger) !== []) {
                $codes[] = $trigger->value;
            }
        }

        foreach (array_keys((array) ($this->data['escalations']['custom'] ?? [])) as $index) {
            $codes[] = 'custom_'.($index + 1);
        }

        return $codes;
    }

    /**
     * The actions of an escalation code the version enabled (a trigger or custom_N), else [].
     *
     * @return list<EscalationAction>
     */
    public function actionsFor(string $code): array
    {
        $trigger = EscalationTrigger::tryFrom($code);

        if ($trigger !== null) {
            return $this->actions($trigger);
        }

        if (preg_match('/^custom_([1-9])\z/', $code, $match) === 1) {
            $custom = ((array) ($this->data['escalations']['custom'] ?? []))[(int) $match[1] - 1] ?? null;
            $action = is_array($custom) ? EscalationAction::tryFrom((string) ($custom['action'] ?? '')) : null;

            return $action === null ? [] : [$action];
        }

        return [];
    }

    public function confidenceMin(): ?int
    {
        return isset($this->data['confidence_min']) ? (int) $this->data['confidence_min'] : null;
    }

    /**
     * The prompt block (pure function of the data, ED46-1). Empty guidance still carries the intro.
     */
    public function render(string $notes = ''): string
    {
        $lines = [self::INTRO];
        $d = $this->data;

        if (isset($d['tone'])) {
            $line = 'Tone: '.str_replace('_', ' ', (string) ($d['tone']['preset'] ?? ''));
            $note = self::clean((string) ($d['tone']['note'] ?? ''));
            $lines[] = $note === '' ? $line.'.' : $line.'. '.$note;
        }

        if (($d['must_ask'] ?? []) !== []) {
            $lines[] = "Ask the client, when still unknown and relevant:\n".self::bullets($d['must_ask']);
        }

        if (isset($d['escalations'])) {
            $rows = [];

            foreach (EscalationTrigger::cases() as $trigger) {
                $actions = $this->actions($trigger);

                if ($actions !== []) {
                    $rows[] = '- '.$trigger->value.': '.implode(', ', array_map(fn (EscalationAction $action): string => $action->value, $actions));
                }
            }

            foreach ((array) ($d['escalations']['custom'] ?? []) as $index => $custom) {
                $rows[] = '- custom_'.($index + 1).': '.self::clean((string) ($custom['text'] ?? '')).' ('.($custom['action'] ?? 'flag').')';
            }

            if ($rows !== []) {
                $lines[] = "Escalation topics (a human must take over; report the topic code):\n".implode("\n", $rows);
            }
        }

        if (($d['service_facts'] ?? []) !== []) {
            $lines[] = "Service facts you may state as given:\n".self::bullets($d['service_facts']);
        }

        if (($d['forbidden_topics'] ?? []) !== []) {
            $lines[] = "Never discuss these topics:\n".self::bullets($d['forbidden_topics']);
        }

        if (isset($d['routing_hints'])) {
            foreach ($d['routing_hints'] as $role => $hint) {
                $hint = self::clean((string) $hint);

                if ($hint !== '') {
                    $lines[] = "Routing hint for {$role}: {$hint}";
                }
            }
        }

        if ($notes !== '') {
            $lines[] = "Operator notes (same priority as the guidance above):\n".$notes;
        }

        return '<'.self::TAG.">\n".implode("\n\n", $lines)."\n</".self::TAG.'>';
    }

    /**
     * The block of a version without guidance (legacy): the same wrapper, intro and cleaned notes.
     */
    public static function wrapNotes(string $notes): string
    {
        return '<'.self::TAG.">\n".self::INTRO.($notes !== '' ? "\n\nOperator notes (same priority as the guidance above):\n".$notes : '')."\n</".self::TAG.'>';
    }

    /**
     * Free text for the prompt (instructions, rules, knowledge, examples) with the line breaks kept: the same
     * stripping as one field (angle brackets, tag name, [CoreRules], separator and fence runs, invisible characters).
     */
    public static function cleanMultiline(string $text): string
    {
        $text = (string) preg_replace('/\r\n?/', "\n", $text);
        $lines = array_map(fn (string $line): string => self::clean($line), explode("\n", $text));

        return trim((string) preg_replace('/\n{3,}/', "\n\n", implode("\n", $lines)));
    }

    /**
     * Every field as plain lines (also what the prompt does not carry: sources,
     * confidence floor), for the version diff. Empty for no data.
     */
    public function canonicalText(): string
    {
        $d = $this->data;
        $lines = [];

        if (isset($d['tone'])) {
            $lines[] = 'tone: '.$d['tone']['preset'].(($d['tone']['note'] ?? '') !== '' ? ' - '.$d['tone']['note'] : '');
        }

        foreach (['must_ask', 'service_facts', 'forbidden_topics'] as $key) {
            foreach ((array) ($d[$key] ?? []) as $item) {
                $lines[] = $key.': '.$item;
            }
        }

        foreach (EscalationTrigger::cases() as $trigger) {
            if (isset($d['escalations'])) {
                $lines[] = 'escalation '.$trigger->value.': '.implode(', ', array_map(fn (EscalationAction $action): string => $action->value, $this->actions($trigger)));
            }
        }

        foreach ((array) ($d['escalations']['custom'] ?? []) as $index => $custom) {
            $lines[] = 'escalation custom_'.($index + 1).': '.$custom['text'].' ('.$custom['action'].')';
        }

        if (isset($d['knowledge'])) {
            $lines[] = 'knowledge sources: '.implode(', ', $d['knowledge']['sources']).' (top '.$d['knowledge']['top_n'].')';
        }

        if (isset($d['confidence_min'])) {
            $lines[] = 'confidence_min: '.$d['confidence_min'];
        }

        foreach ((array) ($d['routing_hints'] ?? []) as $role => $hint) {
            if ($hint !== '') {
                $lines[] = 'routing_hint '.$role.': '.$hint;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    private static function limits(): array
    {
        return (array) config('office.education.guidance');
    }

    private static function defaultConfidence(AgentRole $role): int
    {
        return (int) config('office.education.guidance.confidence_defaults.'.$role->value, 60);
    }

    /**
     * The escalation matrix: known triggers and actions only, deduplicated, the discount lock applied;
     * a trigger missing from the input keeps its default.
     *
     * @return array{triggers: array<string, list<string>>, custom: list<array{text: string, action: string}>}
     */
    private static function escalations(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $given = is_array($raw['triggers'] ?? null) ? $raw['triggers'] : [];
        $triggers = [];

        foreach (EscalationTrigger::cases() as $trigger) {
            if ($trigger->isLocked() || ! array_key_exists($trigger->value, $given)) {
                $chosen = array_map(fn (EscalationAction $action): string => $action->value, $trigger->defaultActions());
            } else {
                $chosen = self::stringList($given[$trigger->value]);
            }

            $triggers[$trigger->value] = array_values(array_map(
                fn (EscalationAction $action): string => $action->value,
                array_filter(EscalationAction::cases(), fn (EscalationAction $action): bool => in_array($action->value, $chosen, true)),
            ));
        }

        $custom = [];
        $limits = self::limits();

        foreach (is_array($raw['custom'] ?? null) ? $raw['custom'] : [] as $row) {
            $text = is_array($row) ? self::text($row['text'] ?? '', (int) $limits['custom_escalation_chars']) : '';

            if ($text !== '' && count($custom) < (int) $limits['custom_escalations']) {
                $custom[] = ['text' => $text, 'action' => (EscalationAction::tryFrom(self::str($row['action'] ?? '')) ?? EscalationAction::Flag)->value];
            }
        }

        return ['triggers' => $triggers, 'custom' => $custom];
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $raw, int $items, int $chars): array
    {
        $out = [];

        foreach (is_array($raw) ? $raw : [] as $value) {
            $value = self::text($value, $chars);

            if ($value !== '' && count($out) < $items) {
                $out[] = $value;
            }
        }

        return $out;
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * Only the string items of an array (a tampered payload never reaches a string cast).
     *
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    private static function text(mixed $value, int $max): string
    {
        return is_scalar($value) ? mb_substr(trim((string) $value), 0, $max) : '';
    }

    /**
     * @param  list<string>  $items
     */
    private static function bullets(array $items): string
    {
        return implode("\n", array_map(fn (string $item): string => '- '.self::clean($item), $items));
    }

    /**
     * One field for the prompt: no angle brackets, tag name, [CoreRules] marker,
     * separator or fence lines, on a single line.
     */
    private static function clean(string $text): string
    {
        $normalised = \Normalizer::normalize($text, \Normalizer::FORM_KC);
        $text = is_string($normalised) ? $normalised : $text;
        $text = (string) preg_replace('/\p{Cf}/u', '', $text);
        $text = (string) preg_replace('/[<>]/u', '', $text);
        $text = (string) preg_replace('/\[\s*core\s*rules?\s*\]/iu', '', $text);
        $text = (string) preg_replace('/operator[\s_-]*guidance/iu', '', $text);
        $text = (string) preg_replace('/(?:={3,}|-{3,}|#{3,}|`{3,}|~{3,}|\*{3,}|_{3,})/u', ' ', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }
}
