<?php

namespace App\Support\Content\AiProduct;

use App\Support\Content\AiFill\AiAnswerParser;
use JsonException;

/**
 * Facts of stage A "look at the photos" (plan 32 §6, PW-13). Runs twice:
 * on the model's answer (lenient: too long or unsafe items are dropped with a
 * warning) and again on the facts the user confirmed in step 3a (strict: any
 * out-of-bounds or unsafe value is an error and nothing goes to stage B).
 *
 * Shape: device_type ≤ 80, brand ≤ 60, models / part_numbers ≤ 5 × ≤ 60,
 * features ≤ 15 × ≤ 120, accessories ≤ 10 × ≤ 80, views[{index 1..n,
 * note ≤ 100, warnings ⊂ WARNINGS}], main_image 1..n (else 1), unsure ⊂ the
 * field names. Strings only, control/format/bidi characters removed,
 * whitespace collapsed, ProductTextGuard on every value. Messages are lang
 * keys (admin.ai_wizard.facts_errors.*), never the input.
 */
final class AiFactsParser
{
    public const WARNINGS = ['blurry', 'not_product', 'people', 'document', 'screenshot'];

    public const FIELDS = ['device_type', 'brand', 'models', 'part_numbers', 'features', 'accessories'];

    /**
     * field => [max items (0 = single string), max characters]
     */
    public const LIMITS = [
        'device_type' => [0, 80],
        'brand' => [0, 60],
        'models' => [5, 60],
        'part_numbers' => [5, 60],
        'features' => [15, 120],
        'accessories' => [10, 80],
    ];

    public const NOTE_MAX = 100;

    private const MAX_BYTES = 65536;

    public function __construct(private readonly ProductTextGuard $guard) {}

    /**
     * The JSON schema of the stage A answer (output_config.format).
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $list = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'properties' => [
                'device_type' => ['type' => 'string'],
                'brand' => ['type' => 'string'],
                'models' => $list,
                'part_numbers' => $list,
                'features' => $list,
                'accessories' => $list,
                'views' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'index' => ['type' => 'integer'],
                        'note' => ['type' => 'string'],
                        'warnings' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => self::WARNINGS]],
                    ],
                    'required' => ['index', 'note', 'warnings'],
                    'additionalProperties' => false,
                ]],
                'main_image' => ['type' => 'integer'],
                'unsure' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => self::FIELDS]],
            ],
            'required' => ['device_type', 'brand', 'models', 'part_numbers', 'features', 'accessories', 'views', 'main_image', 'unsure'],
            'additionalProperties' => false,
        ];
    }

    /**
     * An empty facts set for $images images.
     *
     * @return array<string, mixed>
     */
    public static function blank(int $images = 0): array
    {
        return [
            'device_type' => '',
            'brand' => '',
            'models' => [],
            'part_numbers' => [],
            'features' => [],
            'accessories' => [],
            'views' => array_map(fn (int $index): array => ['index' => $index, 'note' => '', 'warnings' => []], $images > 0 ? range(1, $images) : []),
            'main_image' => 1,
            'unsure' => [],
        ];
    }

    /**
     * The model's answer (lenient).
     *
     * @return array{facts: array<string, mixed>|null, errors: list<string>, warnings: list<string>}
     */
    public function parseAnswer(string $raw, int $images): array
    {
        $data = $this->decode($raw);

        if ($data === null) {
            return ['facts' => null, 'errors' => ['invalid'], 'warnings' => []];
        }

        return $this->read($data, $images, strict: false);
    }

    /**
     * Facts the user confirmed (strict).
     *
     * @param  array<mixed>  $facts
     * @return array{facts: array<string, mixed>|null, errors: list<string>, warnings: list<string>}
     */
    public function parseConfirmed(array $facts, int $images): array
    {
        $result = $this->read($facts, $images, strict: true);

        return $result['errors'] === [] ? $result : ['facts' => null, 'errors' => $result['errors'], 'warnings' => $result['warnings']];
    }

    /**
     * @param  array<mixed>  $data
     * @return array{facts: array<string, mixed>, errors: list<string>, warnings: list<string>}
     */
    private function read(array $data, int $images, bool $strict): array
    {
        $facts = self::blank(0);
        $errors = [];
        $warnings = [];

        foreach (self::LIMITS as $field => [$items, $max]) {
            $value = $data[$field] ?? null;

            if ($items === 0) {
                $facts[$field] = $this->text($value, $max, $field, $strict, $errors, $warnings);

                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            if (! is_array($value) || ! array_is_list($value)) {
                $this->problem("{$field}.invalid", $strict, $errors, $warnings);

                continue;
            }

            if (count($value) > $items) {
                $this->problem("{$field}.too_many", $strict, $errors, $warnings);
                $value = array_slice($value, 0, $items);
            }

            foreach ($value as $item) {
                $text = $this->text($item, $max, $field, $strict, $errors, $warnings);

                if ($text !== '' && ! in_array($text, $facts[$field], true)) {
                    $facts[$field][] = $text;
                }
            }
        }

        $views = is_array($data['views'] ?? null) && array_is_list($data['views']) ? $data['views'] : [];
        $byIndex = [];

        foreach ($views as $view) {
            $index = is_array($view) ? filter_var($view['index'] ?? null, FILTER_VALIDATE_INT) : false;

            if ($index === false || $index < 1 || $index > $images || isset($byIndex[$index])) {
                $this->problem('views.invalid', $strict, $errors, $warnings);

                continue;
            }

            $flags = is_array($view['warnings'] ?? null) ? $view['warnings'] : [];
            $byIndex[$index] = [
                'index' => $index,
                'note' => $this->text($view['note'] ?? '', self::NOTE_MAX, 'views', $strict, $errors, $warnings),
                'warnings' => array_values(array_unique(array_filter($flags, fn (mixed $flag): bool => is_string($flag) && in_array($flag, self::WARNINGS, true)))),
            ];
        }

        $facts['views'] = [];

        for ($index = 1; $index <= $images; $index++) {
            $facts['views'][] = $byIndex[$index] ?? ['index' => $index, 'note' => '', 'warnings' => []];
        }

        $main = filter_var($data['main_image'] ?? 1, FILTER_VALIDATE_INT);
        $facts['main_image'] = $main !== false && $main >= 1 && $main <= max(1, $images) ? $main : 1;

        $unsure = is_array($data['unsure'] ?? null) ? $data['unsure'] : [];
        $facts['unsure'] = array_values(array_unique(array_filter($unsure, fn (mixed $field): bool => is_string($field) && in_array($field, self::FIELDS, true))));

        return ['facts' => $facts, 'errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private function text(mixed $value, int $max, string $field, bool $strict, array &$errors, array &$warnings): string
    {
        if ($value === null) {
            return '';
        }

        if (! is_string($value)) {
            $this->problem("{$field}.invalid", $strict, $errors, $warnings);

            return '';
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            $this->problem("{$field}.invalid", $strict, $errors, $warnings);

            return '';
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\p{Cf}/u', '', AiAnswerParser::cleanCharacters($value))));

        if ($text === '') {
            return '';
        }

        if (str_contains($text, '<')) {
            $this->problem("{$field}.unsafe", $strict, $errors, $warnings);

            return '';
        }

        if (mb_strlen($text) > $max) {
            if ($strict) {
                $errors[] = "{$field}.too_long";

                return '';
            }

            $warnings[] = "{$field}.too_long";
            $text = rtrim(mb_substr($text, 0, $max));
        }

        $checked = $this->guard->check($text);

        if ($checked['errors'] !== [] || $checked['warnings'] !== array_values(array_intersect($checked['warnings'], ['percent', 'promise']))) {
            $this->problem("{$field}.unsafe", $strict, $errors, $warnings);

            return '';
        }

        return $checked['text'];
    }

    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private function problem(string $key, bool $strict, array &$errors, array &$warnings): void
    {
        if ($strict) {
            $errors[] = $key;
        } else {
            $warnings[] = $key;
        }
    }

    /**
     * @return array<mixed>|null
     */
    private function decode(string $raw): ?array
    {
        if (strlen($raw) > self::MAX_BYTES || ! mb_check_encoding($raw, 'UTF-8')) {
            return null;
        }

        $raw = trim($raw);

        if (preg_match('/\A```[A-Za-z0-9_-]*[ \t]*\n(.*?)\n?```\z/s', $raw, $match) === 1) {
            $raw = trim($match[1]);
        }

        try {
            $data = json_decode($raw, true, 5, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($data) && ! array_is_list($data) ? $data : null;
    }
}
