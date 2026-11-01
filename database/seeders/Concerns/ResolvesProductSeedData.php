<?php

namespace Database\Seeders\Concerns;

use App\Enums\AttributeType;
use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Product;
use App\Models\RollType;
use Illuminate\Support\Collection;

/**
 * Resolves the spec / roll type values of seeded product rows against the
 * seeded attribute library (shared by ProductsSeeder and the test fixture
 * seeder). Never creates attributes or options: unknown codes or values are
 * collected in $warnings ("SKU: message") and skipped.
 */
trait ResolvesProductSeedData
{
    /**
     * Unmapped spec problems of the last run ("SKU: message").
     *
     * @var list<string>
     */
    public array $warnings = [];

    /**
     * @param  array<string, mixed>  $specs  code => value or ['value' => ..., 'key' => bool]
     * @param  Collection<string, Attribute>  $attributes
     * @return list<array<string, mixed>>
     */
    protected function specs(string $sku, array $specs, Collection $attributes): array
    {
        $rows = [];
        $keys = 0;

        foreach ($specs as $code => $spec) {
            $attribute = $attributes->get($code);
            $value = is_array($spec) && array_key_exists('value', $spec) ? $spec['value'] : $spec;
            $isKey = is_array($spec) && ($spec['key'] ?? false);

            if ($attribute === null) {
                $this->warn($sku, "unknown attribute {$code}");

                continue;
            }

            if ($isKey && $keys >= Product::MAX_KEY_SPECS) {
                $this->warn($sku, 'more than '.Product::MAX_KEY_SPECS." key specs, {$code} is not a key spec");
                $isKey = false;
            }

            $row = ['attribute_id' => $attribute->id, 'skipped' => false, 'is_key' => $isKey];

            switch ($attribute->type) {
                case AttributeType::Text:
                    $row['text'] = is_array($value) ? $value : ['uz' => (string) $value];
                    break;
                case AttributeType::Number:
                    $row['number'] = (string) $value;
                    break;
                case AttributeType::Boolean:
                    $row['bool'] = (bool) $value;
                    break;
                case AttributeType::Select:
                    $row['option'] = $this->optionId($sku, $attribute, (string) $value);

                    if ($row['option'] === null) {
                        continue 2;
                    }
                    break;
                case AttributeType::Multiselect:
                    $row['options'] = array_values(array_filter(array_map(
                        fn ($item): ?int => $this->optionId($sku, $attribute, (string) $item),
                        (array) $value,
                    )));
                    break;
            }

            $keys += $isKey ? 1 : 0;
            $rows[] = $row;
        }

        return $rows;
    }

    protected function optionId(string $sku, Attribute $attribute, string $value): ?int
    {
        $needle = mb_strtolower(trim($value));
        $option = $attribute->options->first(
            fn (AttributeOption $option): bool => mb_strtolower(trim((string) $option->getTranslation('value', 'uz', false))) === $needle,
        );

        if ($option === null) {
            $this->warn($sku, "no option “{$value}” for {$attribute->code}");
        }

        return $option?->id;
    }

    /**
     * @param  list<array{core: string, labels: int}>  $pairs
     * @param  Collection<string, RollType>  $rollTypes  keyed "labels@core" ("1000@40.0")
     * @return list<int>
     */
    protected function rollTypeIds(string $sku, array $pairs, Collection $rollTypes): array
    {
        $ids = [];

        foreach ($pairs as $pair) {
            $rollType = $rollTypes->get($pair['labels'].'@'.number_format((float) $pair['core'], 1, '.', ''));

            if ($rollType === null) {
                $this->warn($sku, "no roll type {$pair['labels']} labels / {$pair['core']} mm");

                continue;
            }

            $ids[] = $rollType->id;
        }

        return $ids;
    }

    protected function warn(string $sku, string $message): void
    {
        $this->warnings[] = "{$sku}: {$message}";
    }
}
