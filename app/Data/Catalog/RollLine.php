<?php

namespace App\Data\Catalog;

/**
 * One line of a label product sold in rolls (plan 08), built by
 * App\Support\Catalog\RollPricing::line(). The same contract serves quote
 * lines, lead items and (later) cart / order lines:
 *
 * - rolls: the line quantity (qty = rolls);
 * - labelsPerRoll / coreDiameterMm: the roll type snapshot ("40.0");
 * - labelSize: "100×150" from the product's label_width / label_height, null
 *   when either is missing;
 * - pricePerLabel: tiyin, whole soʻm (the source of truth managers edit);
 * - rollPrice = pricePerLabel × labelsPerRoll; total = rollPrice × rolls.
 *
 * pricePerLabel / rollPrice / total are null when the price is on request
 * (quote only, no amounts shown). All money is integer tiyin.
 */
final readonly class RollLine
{
    public function __construct(
        public ?int $productId,
        public ?int $rollTypeId,
        public int $rolls,
        public int $labelsPerRoll,
        public string $coreDiameterMm,
        public ?string $labelSize,
        public ?int $pricePerLabel,
        public ?int $rollPrice,
        public ?int $total,
    ) {}

    public function hasPrice(): bool
    {
        return $this->pricePerLabel !== null;
    }

    /**
     * Snapshot columns of quote_items / lead_items (and later order_items).
     *
     * @return array{roll_type_id: int|null, labels_per_roll: int, core_diameter_mm: string, label_size: string|null, price_per_label: int|null}
     */
    public function snapshot(): array
    {
        return [
            'roll_type_id' => $this->rollTypeId,
            'labels_per_roll' => $this->labelsPerRoll,
            'core_diameter_mm' => $this->coreDiameterMm,
            'label_size' => $this->labelSize,
            'price_per_label' => $this->pricePerLabel,
        ];
    }
}
