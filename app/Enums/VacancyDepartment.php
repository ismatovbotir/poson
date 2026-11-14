<?php

namespace App\Enums;

/**
 * Department a vacancy belongs to (plan 31).
 */
enum VacancyDepartment: string
{
    case Service = 'service';
    case Sales = 'sales';
    case Software = 'software';
    case Operations = 'operations';
    case Management = 'management';
    case Marketing = 'marketing';

    public function label(): string
    {
        return __("enums.vacancy_department.{$this->value}");
    }
}
