<?php

namespace App\Support\Catalog;

use App\Models\Product;
use App\Models\ProductAttributeValue;

/**
 * Software details managed as spec attributes (decision 2026-10-22,
 * business-facts row 24): platforms, licence model, segment, modules,
 * integrations, fiscal receipts (online cash register) and interface
 * languages. The vendor site is the brand website (also the "Try" link).
 * They are seeded on the "software" category
 * (database/seeders/data/attributes.php), are all optional and are edited
 * per product in admin like any other spec.
 *
 * forProduct() groups a product's values for the "program details" block
 * of the product page (chips and a modules list); the spec table leaves
 * them out (BLOCK_CODES).
 */
class SoftwareSpecs
{
    public const PLATFORMS = 'platforms';

    public const LICENCE_MODEL = 'licence_model';

    public const SEGMENT = 'segment';

    public const MODULES = 'modules';

    public const INTEGRATIONS = 'integrations';

    public const ONLINE_CASH_REGISTER = 'online_cash_register';

    public const INTERFACE_LANGUAGES = 'interface_languages';

    /**
     * Attributes shown as chips, in display order.
     *
     * @var list<string>
     */
    public const CHIP_CODES = [self::PLATFORMS, self::INTERFACE_LANGUAGES, self::SEGMENT, self::LICENCE_MODEL, self::INTEGRATIONS];

    /**
     * Everything the software block shows; the spec table leaves these out
     * for software, so nothing is shown twice.
     *
     * @var list<string>
     */
    public const BLOCK_CODES = [...self::CHIP_CODES, self::MODULES, self::ONLINE_CASH_REGISTER];

    /**
     * The product's software details from its loaded attributeValues
     * (with attribute and option), skipping skipped attributes. Empty
     * groups are left out; an empty array means nothing to show.
     *
     * @param  list<int>  $skippedAttributeIds
     * @return array{
     *     chips: list<array{code: string, label: string, values: list<string>}>,
     *     modules: array{label: string, values: list<string>}|null,
     *     online_cash_register: array{label: string, value: bool}|null
     * }|array{}
     */
    public static function forProduct(Product $product, array $skippedAttributeIds = []): array
    {
        $byCode = [];

        /** @var ProductAttributeValue $value */
        foreach ($product->getRelationValue('attributeValues') ?? [] as $value) {
            $attribute = $value->getRelationValue('attribute');

            if ($attribute === null || in_array((int) $value->attribute_id, $skippedAttributeIds, true)) {
                continue;
            }

            $byCode[$attribute->code]['label'] = (string) $attribute->name;
            $byCode[$attribute->code]['type'] = $attribute->type;
            $byCode[$attribute->code]['values'][] = $value;
        }

        $chips = [];

        foreach (self::CHIP_CODES as $code) {
            $values = self::displayValues($byCode[$code]['values'] ?? []);

            if ($values !== []) {
                $chips[] = ['code' => $code, 'label' => $byCode[$code]['label'], 'values' => $values];
            }
        }

        $modules = self::displayValues($byCode[self::MODULES]['values'] ?? []);
        $fiscal = ($byCode[self::ONLINE_CASH_REGISTER]['values'] ?? [])[0]->value_bool ?? null;

        $details = [
            'chips' => $chips,
            'modules' => $modules !== [] ? ['label' => $byCode[self::MODULES]['label'], 'values' => $modules] : null,
            'online_cash_register' => $fiscal !== null ? ['label' => $byCode[self::ONLINE_CASH_REGISTER]['label'], 'value' => (bool) $fiscal] : null,
        ];

        return $chips === [] && $details['modules'] === null && $details['online_cash_register'] === null
            ? []
            : $details;
    }

    /**
     * @param  list<ProductAttributeValue>  $values
     * @return list<string>
     */
    private static function displayValues(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (ProductAttributeValue $value): ?string => $value->displayValue(),
            $values,
        ))));
    }
}
