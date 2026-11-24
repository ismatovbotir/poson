<?php

namespace App\Livewire\Forms\Admin\System;

use App\Support\Monitoring\DiskThresholds;
use Livewire\Form;

/**
 * Project size limits in whole GB (plan 37, DM-11). The action validates again.
 */
class DiskThresholdsForm extends Form
{
    public mixed $warn = '';

    public mixed $critical = '';

    public function fillFrom(DiskThresholds $thresholds): void
    {
        ['warn_gb' => $warn, 'critical_gb' => $critical] = $thresholds->gb();

        $this->warn = (string) $warn;
        $this->critical = (string) $critical;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'warn' => ['required', 'integer', 'min:'.DiskThresholds::WARN_MIN, 'max:'.DiskThresholds::WARN_MAX],
            'critical' => ['required', 'integer', 'gt:warn', 'max:'.DiskThresholds::CRITICAL_MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'warn' => __('monitoring.disk.form.warn'),
            'critical' => __('monitoring.disk.form.critical'),
        ];
    }
}
