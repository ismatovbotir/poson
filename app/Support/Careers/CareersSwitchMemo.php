<?php

namespace App\Support\Careers;

/**
 * Per request / per job memo of CareersSwitch; registered as a scoped binding
 * (AppServiceProvider), so the container drops it between jobs and requests.
 */
final class CareersSwitchMemo
{
    /**
     * @var array<string, bool>
     */
    public array $values = [];
}
