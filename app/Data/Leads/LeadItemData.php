<?php

namespace App\Data\Leads;

/**
 * One product a lead asks about (CreateLead / UpdateLead). The SKU and name
 * snapshots are read from the product, never taken from the input.
 *
 * itemId: an existing lead_items row (UpdateLead keeps its snapshots and
 * only changes qty, note and order); null for a new row, which needs a
 * productId.
 *
 * rollTypeId (plan 08): the roll a label product is asked for in (qty is
 * then rolls); it must be attached to the product and active.
 */
final readonly class LeadItemData
{
    public function __construct(
        public ?int $productId,
        public int $qty = 1,
        public ?string $note = null,
        public ?int $itemId = null,
        public ?int $rollTypeId = null,
    ) {}
}
