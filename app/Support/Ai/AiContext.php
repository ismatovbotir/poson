<?php

namespace App\Support\Ai;

use Illuminate\Support\Str;

/**
 * Facts about the record being edited, built server-side from the unsaved
 * form state (ProductAiContext, CategoryAiContext). Sent to the model inside
 * <context> tags as data, never as instructions. Carries no personal or
 * CRM data. Values are trimmed and capped (MAX_VALUE / MAX_SPECS).
 *
 * - name / category / brand / specs / question fill the hint placeholders
 * - facts: further labelled lines (type, model, current texts, ...)
 * - subjectType / subjectId: the saved record (morph alias), for the log
 */
final readonly class AiContext
{
    public const MAX_VALUE = 1500;

    public const MAX_SPECS = 4000;

    public const MAX_FACTS = 30;

    /**
     * @param  array<string, string>  $facts  label => value
     */
    public function __construct(
        public string $name = '',
        public string $category = '',
        public string $brand = '',
        public string $specs = '',
        public string $question = '',
        public array $facts = [],
        public ?string $subjectType = null,
        public ?int $subjectId = null,
    ) {}

    /**
     * Values for FieldRegistry::fill().
     *
     * @return array<string, string>
     */
    public function placeholders(): array
    {
        return [
            'name' => $this->name,
            'category' => $this->category,
            'brand' => $this->brand,
            'specs' => $this->specs,
            'question' => $this->question,
        ];
    }

    /**
     * The context block of the user message. Any "</context" in the data is
     * neutralized so the block can't be closed from inside.
     */
    public function toPrompt(): string
    {
        $lines = [];

        foreach (['Name' => $this->name, 'Category' => $this->category, 'Brand' => $this->brand, 'Question' => $this->question] as $label => $value) {
            if ($value !== '') {
                $lines[] = "{$label}: {$value}";
            }
        }

        foreach ($this->facts as $label => $value) {
            $lines[] = "{$label}: {$value}";
        }

        if ($this->specs !== '') {
            $lines[] = "Specifications:\n{$this->specs}";
        }

        return "<context>\n".self::neutralize(implode("\n", $lines))."\n</context>";
    }

    /**
     * @return array{name: string, category: string, brand: string, specs: string, question: string, facts: array<string, string>, subject_type: string|null, subject_id: int|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'category' => $this->category,
            'brand' => $this->brand,
            'specs' => $this->specs,
            'question' => $this->question,
            'facts' => $this->facts,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
        ];
    }

    /**
     * Rebuild from toArray() output (e.g. a Livewire event payload), with
     * every value cleaned and capped again.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $facts = [];

        foreach (array_slice(is_array($data['facts'] ?? null) ? $data['facts'] : [], 0, self::MAX_FACTS, true) as $label => $value) {
            if (is_string($label) && is_scalar($value) && ($clean = self::clean((string) $value)) !== '') {
                $facts[self::clean($label, 60)] = $clean;
            }
        }

        $type = $data['subject_type'] ?? null;
        $id = $data['subject_id'] ?? null;

        return new self(
            name: self::clean($data['name'] ?? ''),
            category: self::clean($data['category'] ?? ''),
            brand: self::clean($data['brand'] ?? ''),
            specs: self::clean($data['specs'] ?? '', self::MAX_SPECS, multiline: true),
            question: self::clean($data['question'] ?? ''),
            facts: $facts,
            subjectType: in_array($type, ['category', 'product', 'brand', 'page', 'news_post'], true) ? $type : null,
            subjectId: is_int($id) && $id > 0 ? $id : null,
        );
    }

    /**
     * Plain, trimmed and capped text: tags stripped, whitespace collapsed
     * (line breaks kept for multiline values).
     */
    public static function clean(mixed $value, int $max = self::MAX_VALUE, bool $multiline = false): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $text = html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />', '</li>'], "\n", (string) $value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = $multiline
            ? trim((string) preg_replace(["/[^\S\n]+/u", "/\n{2,}/"], [' ', "\n"], $text))
            : trim((string) preg_replace('/\s+/u', ' ', $text));

        return Str::limit($text, $max, '…');
    }

    /**
     * Break any closing wrapper tag inside user data.
     */
    public static function neutralize(string $text): string
    {
        return (string) preg_replace('#<\s*/?\s*(context|source)\b#i', '‹$1', $text);
    }
}
