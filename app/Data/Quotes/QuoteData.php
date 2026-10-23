<?php

namespace App\Data\Quotes;

/**
 * Input of CreateQuote / UpdateQuote. validUntil is Y-m-d (null: 5 working
 * days from today on create, unchanged on update); locale null = the
 * customer's locale. The customer and lead are only read on create.
 */
final readonly class QuoteData
{
    /**
     * @param  list<QuoteItemData>|null  $items  null on create from a lead: copy the lead's items
     */
    public function __construct(
        public int $customerId,
        public ?int $leadId = null,
        public ?array $items = null,
        public ?string $validUntil = null,
        public ?string $notes = null,
        public ?string $locale = null,
    ) {}
}
