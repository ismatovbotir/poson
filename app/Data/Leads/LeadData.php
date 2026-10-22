<?php

namespace App\Data\Leads;

use App\Enums\LeadSource;
use App\Enums\LeadType;
use App\Enums\TrafficChannel;

/**
 * Input of CreateLead / UpdateLead, for every channel (site form, admin,
 * later Telegram). The phone may be in any format; the action normalizes it.
 *
 * managerId: chosen manager (admin form); null lets CreateLead auto-assign
 * a sales lead to the matched customer's manager. createdById: the staff
 * user who entered it (null for the site).
 */
final readonly class LeadData
{
    /**
     * @param  list<LeadItemData>  $items
     * @param  array<string, string>  $utm
     */
    public function __construct(
        public LeadSource $source,
        public LeadType $type,
        public string $name,
        public string $phone,
        public string $locale,
        public ?string $email = null,
        public ?string $company = null,
        public ?string $inn = null,
        public ?string $message = null,
        public array $items = [],
        public ?int $managerId = null,
        public ?int $createdById = null,
        public ?int $estimatedAmount = null,
        public array $utm = [],
        public ?string $pageUrl = null,
        public ?TrafficChannel $trafficChannel = null,
        public ?string $trafficSource = null,
        public ?string $landingPath = null,
    ) {}
}
