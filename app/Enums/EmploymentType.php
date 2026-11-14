<?php

namespace App\Enums;

/**
 * Employment type of a vacancy (plan 31). schemaType() is the schema.org
 * JobPosting employmentType value (JSON-LD, phase C).
 */
enum EmploymentType: string
{
    case FullTime = 'full_time';
    case PartTime = 'part_time';
    case Contract = 'contract';
    case Internship = 'internship';

    public function label(): string
    {
        return __("enums.employment_type.{$this->value}");
    }

    public function schemaType(): string
    {
        return match ($this) {
            self::FullTime => 'FULL_TIME',
            self::PartTime => 'PART_TIME',
            self::Contract => 'CONTRACTOR',
            self::Internship => 'INTERN',
        };
    }
}
