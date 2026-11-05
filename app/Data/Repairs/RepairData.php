<?php

namespace App\Data\Repairs;

use App\Enums\RepairCoverage;
use App\Enums\RepairDeviceType;

/**
 * Input of CreateRepair / UpdateRepair (staff intake and edit form).
 *
 * costsIncluded: the caller was allowed to see and edit the amounts; only
 * then are estimateAmount / finalAmount (tiyin, null = empty) applied. A
 * form without repairs.costs sends false, so the stored amounts stay as
 * they are. assigneeId is used on create only.
 */
final readonly class RepairData
{
    public function __construct(
        public string $contactName,
        public string $contactPhone,
        public RepairDeviceType $deviceType,
        public string $intakeCondition,
        public string $problem,
        public RepairCoverage $coverage = RepairCoverage::Paid,
        public ?string $company = null,
        public ?int $customerId = null,
        public ?int $contactId = null,
        public ?int $leadId = null,
        public ?string $deviceBrand = null,
        public ?string $deviceModel = null,
        public ?string $serialNumber = null,
        public ?string $accessories = null,
        public ?string $diagnosis = null,
        public ?string $workDone = null,
        public ?string $partsUsed = null,
        public ?string $warrantyReference = null,
        public bool $costsIncluded = false,
        public ?int $estimateAmount = null,
        public ?int $finalAmount = null,
        public string $locale = 'uz',
        public ?int $assigneeId = null,
    ) {}
}
