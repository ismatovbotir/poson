<?php

namespace App\Data\Careers;

use App\Enums\VacancyDepartment;

/**
 * A validated application from the careers form (plan 31): texts already
 * cleaned (PlainText), phone normalized to E.164. Exactly one of
 * vacancyId (an application to a vacancy) and department (an open
 * application) is set.
 */
final readonly class JobApplicationData
{
    public function __construct(
        public string $name,
        public string $phone,
        public ?string $email,
        public ?string $message,
        public string $locale,
        public ?int $vacancyId = null,
        public ?VacancyDepartment $department = null,
    ) {}
}
