<?php

namespace App\Actions\Catalog\Ai;

use App\Enums\AttributeType;
use App\Models\Product;
use App\Rules\BrandSafeText;
use App\Support\Catalog\ProductFormData;
use App\Support\Content\AiFill\AiAnswerParser;
use App\Support\Content\AiFill\UzApostrophes;
use App\Support\Content\AiProduct\AiSpecParser;
use App\Support\Content\AiProduct\ProductAiDraft;
use App\Support\Content\AiProduct\ProductTextGuard;
use App\Support\Content\ContentLimits;
use Illuminate\Validation\ValidationException;

/**
 * Re-validation of everything the wizard hands to CreateProductFromAiDraft /
 * ApplyAiDraftToProduct (plan 32 PW-14 - PW-16): it comes from Livewire
 * state, so it is user input. Texts: fixed field whitelist, plain or
 * sanitized HTML, ProductTextGuard (links/contacts removed, money/discount =
 * error), BrandSafeText, UzApostrophes on uz, the form's length limits.
 * Alts: plain, ≤ 255. Specs: AiSpecParser::recheck against the CURRENT
 * category (existing option ids of that attribute only). Errors are keyed
 * "texts.{field}.{locale}", "alts.{index}.{locale}" and never echo the text.
 */
class AiDraftProposal
{
    public const ALT_MAX = 255;

    public function __construct(
        private readonly ProductTextGuard $guard,
        private readonly AiSpecParser $specs,
    ) {}

    /**
     * @param  array<string, array<string, string>>  $texts
     * @param  list<string>  $fields  the text fields to take (others are ignored)
     * @return array<string, array<string, string>>
     *
     * @throws ValidationException
     */
    public function texts(array $texts, array $fields): array
    {
        $clean = [];
        $errors = [];

        foreach (array_intersect(ProductAiDraft::TEXT_FIELDS, $fields) as $field) {
            foreach (config('app.supported_locales') as $locale) {
                $raw = $texts[$field][$locale] ?? '';
                $key = "texts.{$field}.{$locale}";

                if (! is_string($raw) || ! mb_check_encoding($raw, 'UTF-8')) {
                    $errors[$key] = __('admin.ai_wizard.errors.text_invalid');

                    continue;
                }

                $text = trim((string) preg_replace('/\p{Cf}/u', '', AiAnswerParser::cleanCharacters($raw)));

                if ($text === '') {
                    continue;
                }

                $html = $field === 'description';
                $text = $locale === 'uz' ? UzApostrophes::normalize($text) : $text;

                if (! $html && str_contains($text, '<')) {
                    $errors[$key] = __('admin.ai_wizard.errors.text_plain');

                    continue;
                }

                $checked = $this->guard->check($text, $html);

                if ($checked['errors'] !== []) {
                    $errors[$key] = __('admin.ai_wizard.guard.'.$checked['errors'][0]);

                    continue;
                }

                if (BrandSafeText::violates(strip_tags($checked['text']))) {
                    $errors[$key] = __('admin.ai_wizard.errors.brand');

                    continue;
                }

                if (mb_strlen($checked['text']) > self::max($field)) {
                    $errors[$key] = __('admin.ai_wizard.errors.too_long', ['max' => self::max($field)]);

                    continue;
                }

                if (trim(strip_tags($checked['text'])) !== '') {
                    $clean[$field][$locale] = $checked['text'];
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    /**
     * @param  array<int, array<string, string>>  $alts  draft image index → locale → alt
     * @return array<int, array<string, string>>
     *
     * @throws ValidationException
     */
    public function alts(array $alts, int $count): array
    {
        $clean = [];
        $errors = [];

        foreach ($alts as $index => $locales) {
            if (! is_int($index) || $index < 0 || $index >= $count || ! is_array($locales)) {
                continue;
            }

            foreach (config('app.supported_locales') as $locale) {
                $raw = $locales[$locale] ?? '';
                $key = "alts.{$index}.{$locale}";
                $text = is_string($raw) && mb_check_encoding($raw, 'UTF-8')
                    ? trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\p{Cf}/u', '', AiAnswerParser::cleanCharacters($raw))))
                    : '';

                if ($text === '') {
                    continue;
                }

                $text = $locale === 'uz' ? UzApostrophes::normalize($text) : $text;
                $checked = $this->guard->check($text);

                if (str_contains($text, '<') || mb_strlen($text) > self::ALT_MAX) {
                    $errors[$key] = __('admin.ai_wizard.errors.alt_invalid', ['max' => self::ALT_MAX]);
                } elseif ($checked['errors'] !== []) {
                    $errors[$key] = __('admin.ai_wizard.guard.'.$checked['errors'][0]);
                } elseif (BrandSafeText::violates($checked['text'])) {
                    $errors[$key] = __('admin.ai_wizard.errors.brand');
                } elseif ($checked['text'] !== '') {
                    $clean[$index][$locale] = $checked['text'];
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    /**
     * The accepted spec proposals, re-checked against the category (PW-16).
     *
     * @param  array<int|string, array<string, mixed>>  $proposals
     * @param  list<int>  $acceptedIds
     * @return array<int, array<string, mixed>>
     */
    public function specs(array $proposals, array $acceptedIds, ?int $categoryId): array
    {
        $chosen = array_values(array_filter($proposals, fn (mixed $row): bool => is_array($row)
            && in_array((int) ($row['attribute_id'] ?? 0), $acceptedIds, true)));

        return $this->specs->recheck($chosen, $categoryId);
    }

    /**
     * Put proposals into the form's spec rows (a missing row is added as an extra; a skip is lifted).
     *
     * @param  list<array<string, mixed>>  $rows  ProductFormData spec rows
     * @param  array<int, array<string, mixed>>  $proposals  checked proposals keyed by attribute id
     * @param  array<int, AttributeType>  $types  attribute id → type
     * @param  bool  $onlyEmpty  keep rows that already hold a value
     * @return list<array<string, mixed>>
     */
    public static function overlaySpecs(array $rows, array $proposals, array $types, bool $onlyEmpty = false): array
    {
        foreach ($proposals as $attributeId => $proposal) {
            $position = null;

            foreach ($rows as $index => $row) {
                if ((int) $row['attribute_id'] === (int) $attributeId) {
                    $position = $index;

                    break;
                }
            }

            if ($position === null) {
                $rows[] = ProductFormData::blankRow((int) $attributeId, true);
                $position = array_key_last($rows);
            }

            $type = $types[(int) $attributeId] ?? null;

            if ($onlyEmpty && $type instanceof AttributeType && ProductFormData::rowHasValue($rows[$position], $type)) {
                continue;
            }

            $rows[$position]['skipped'] = false;

            foreach (['text', 'number', 'bool', 'option', 'options'] as $slot) {
                $rows[$position][$slot] = $proposal[$slot] ?? $rows[$position][$slot];
            }
        }

        return array_values($rows);
    }

    /**
     * Step 1 values as ProductFormData state strings (only the wizard's info keys).
     *
     * @param  array<string, mixed>  $info
     * @return array{category_id: string, brand_id: string, model: string, sku: string, type: string, sale_mode: string, price: string}
     */
    public static function info(array $info): array
    {
        $string = fn (string $key): string => is_scalar($info[$key] ?? null) ? trim((string) $info[$key]) : '';

        return [
            'category_id' => $string('category_id'),
            'brand_id' => $string('brand_id'),
            'model' => $string('model'),
            'sku' => mb_strtoupper($string('sku')),
            'type' => $string('type'),
            'sale_mode' => $string('sale_mode'),
            'price' => $string('price'),
        ];
    }

    /**
     * Accepted spec attribute ids ("spec:{id}" keys).
     *
     * @return list<int>
     */
    public static function acceptedSpecIds(ProductAiDraft $data): array
    {
        $ids = [];

        foreach ($data->accepted as $key) {
            if (preg_match('/\Aspec:(\d{1,10})\z/', $key, $match) === 1) {
                $ids[] = (int) $match[1];
            }
        }

        return array_values(array_unique($ids));
    }

    public static function max(string $field): int
    {
        return match ($field) {
            'name', 'meta_title' => ContentLimits::SHORT_TEXT_MAX,
            'description' => Product::DESCRIPTION_MAX,
            default => 500,
        };
    }
}
