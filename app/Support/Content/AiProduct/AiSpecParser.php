<?php

namespace App\Support\Content\AiProduct;

use App\Enums\AttributeType;
use App\Models\Attribute;
use App\Support\Catalog\ProductAttributeResolver;
use App\Support\Catalog\ResolvedAttribute;
use App\Support\Content\AiFill\AiAnswerParser;
use App\Support\Content\AiFill\UzApostrophes;

/**
 * Spec proposals of stage B (plan 32 §6, PW-16), validated against the
 * attributes of the product's CURRENT category chain only (also again at
 * apply, after a category change). Codes outside it are dropped; numbers
 * must be numeric and |n| < 10^10; booleans yes/no; select / multiselect
 * only EXISTING option ids of that very attribute (no new options); text
 * plain, ≤ 255 per locale, uz required, ProductTextGuard-checked. Every
 * proposal is unsure unless the model says otherwise, and the wizard never
 * pre-accepts an unsure one.
 *
 * A row: attribute_id + the ProductForm value slot of its type + unsure.
 *
 * @phpstan-type SpecProposal array{attribute_id: int, text: array<string, string>, number: string, bool: string, option: string, options: list<int>, unsure: bool}
 */
final class AiSpecParser
{
    public const TEXT_MAX = 255;

    public function __construct(
        private readonly ProductAttributeResolver $resolver,
        private readonly ProductTextGuard $guard,
    ) {}

    /**
     * The JSON schema of one spec proposal.
     *
     * @return array<string, mixed>
     */
    public static function itemSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'code' => ['type' => 'string'],
                'value' => ['type' => 'string'],
                'text' => [
                    'type' => 'object',
                    'properties' => ['uz' => ['type' => 'string'], 'ru' => ['type' => 'string'], 'en' => ['type' => 'string']],
                    'required' => ['uz', 'ru', 'en'],
                    'additionalProperties' => false,
                ],
                'unsure' => ['type' => 'boolean'],
            ],
            'required' => ['code', 'value', 'text', 'unsure'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Attributes the model may fill: the category chain, at most $max (options eager-loaded).
     *
     * @return list<ResolvedAttribute>
     */
    public function attributes(?int $categoryId, ?int $max = null): array
    {
        $rows = $this->resolver->categoryAttributes($categoryId);

        return array_slice($rows, 0, $max ?? (int) config('chatbot.ai.product.attributes_max', 60));
    }

    /**
     * The model's spec list (by code) → proposals keyed by attribute id.
     *
     * @param  mixed  $items  list of {code, value, text{uz,ru,en}, unsure}
     * @return array{specs: array<int, SpecProposal>, dropped: int}
     */
    public function parse(mixed $items, ?int $categoryId): array
    {
        $byCode = [];

        foreach ($this->attributes($categoryId) as $row) {
            $byCode[$row->attribute->code] = $row->attribute;
        }

        $specs = [];
        $dropped = 0;

        foreach (is_array($items) && array_is_list($items) ? array_slice($items, 0, 200) : [] as $item) {
            $code = is_array($item) && is_string($item['code'] ?? null) ? $item['code'] : null;
            $attribute = $code !== null ? ($byCode[$code] ?? null) : null;

            if ($attribute === null || isset($specs[$attribute->id])) {
                $dropped++;

                continue;
            }

            $row = $this->row($attribute, $item['value'] ?? '', $item['text'] ?? []);

            if ($row === null) {
                $dropped++;

                continue;
            }

            $row['unsure'] = ($item['unsure'] ?? true) !== false;
            $specs[$attribute->id] = $row;
        }

        return ['specs' => $specs, 'dropped' => $dropped];
    }

    /**
     * Re-check proposals (from the draft row or the wizard state) against the
     * current category: unknown attributes, other attributes' options and bad
     * values are dropped.
     *
     * @param  array<mixed>  $rows
     * @return array<int, SpecProposal>
     */
    public function recheck(array $rows, ?int $categoryId): array
    {
        $attributes = [];

        foreach ($this->attributes($categoryId, PHP_INT_MAX) as $row) {
            $attributes[$row->attribute->id] = $row->attribute;
        }

        $clean = [];

        foreach ($rows as $row) {
            $id = is_array($row) ? filter_var($row['attribute_id'] ?? null, FILTER_VALIDATE_INT) : false;
            $attribute = $id !== false ? ($attributes[$id] ?? null) : null;

            if ($attribute === null || isset($clean[$attribute->id])) {
                continue;
            }

            $value = match ($attribute->type) {
                AttributeType::Number => $row['number'] ?? '',
                AttributeType::Boolean => match ((string) ($row['bool'] ?? '')) {
                    '1' => 'yes',
                    '0' => 'no',
                    default => '',
                },
                AttributeType::Select => (string) ($row['option'] ?? ''),
                AttributeType::Multiselect => implode(',', array_map(fn (mixed $id): string => is_scalar($id) ? (string) $id : '', is_array($row['options'] ?? null) ? $row['options'] : [])),
                AttributeType::Text => '',
            };

            $checked = $this->row($attribute, $value, $row['text'] ?? []);

            if ($checked !== null) {
                $checked['unsure'] = (bool) ($row['unsure'] ?? true);
                $clean[$attribute->id] = $checked;
            }
        }

        return $clean;
    }

    /**
     * @return SpecProposal|null
     */
    private function row(Attribute $attribute, mixed $value, mixed $text): ?array
    {
        $row = ['attribute_id' => $attribute->id, 'text' => ['uz' => '', 'ru' => '', 'en' => ''], 'number' => '', 'bool' => '', 'option' => '', 'options' => [], 'unsure' => true];
        $value = is_scalar($value) ? trim((string) $value) : '';

        switch ($attribute->type) {
            case AttributeType::Number:
                $number = str_replace([',', ' ', "\u{00A0}"], ['.', '', ''], $value);

                if ($number === '' || ! is_numeric($number) || abs((float) $number) >= 1e10 || strlen($number) > 32) {
                    return null;
                }

                $row['number'] = $number;

                return $row;

            case AttributeType::Boolean:
                $bool = match (mb_strtolower($value)) {
                    'yes', 'true', '1', 'ha', 'да' => '1',
                    'no', 'false', '0', 'yoʻq', 'нет' => '0',
                    default => null,
                };

                if ($bool === null) {
                    return null;
                }

                $row['bool'] = $bool;

                return $row;

            case AttributeType::Select:
            case AttributeType::Multiselect:
                $known = $attribute->options->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $ids = [];

                foreach (explode(',', $value) as $part) {
                    $id = filter_var(trim($part), FILTER_VALIDATE_INT);

                    if ($id === false || ! in_array($id, $known, true)) {
                        return null;
                    }

                    $ids[] = $id;
                }

                $ids = array_values(array_unique($ids));

                if ($ids === [] || ($attribute->type === AttributeType::Select && count($ids) > 1)) {
                    return null;
                }

                if ($attribute->type === AttributeType::Select) {
                    $row['option'] = (string) $ids[0];
                } else {
                    $row['options'] = array_values(array_intersect($known, $ids));
                }

                return $row;

            case AttributeType::Text:
                $texts = is_array($text) ? $text : [];

                foreach (config('app.supported_locales') as $locale) {
                    $raw = $texts[$locale] ?? '';

                    if (! is_string($raw) || ! mb_check_encoding($raw, 'UTF-8')) {
                        return null;
                    }

                    $clean = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\p{Cf}/u', '', AiAnswerParser::cleanCharacters($raw))));

                    if ($clean === '') {
                        continue;
                    }

                    if (str_contains($clean, '<') || mb_strlen($clean) > self::TEXT_MAX) {
                        return null;
                    }

                    $checked = $this->guard->check($locale === 'uz' ? UzApostrophes::normalize($clean) : $clean);

                    if ($checked['errors'] !== [] || $checked['text'] === '') {
                        return null;
                    }

                    $row['text'][$locale] = $checked['text'];
                }

                return $row['text']['uz'] === '' ? null : $row;
        }

        return null;
    }
}
