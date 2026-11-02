<?php

namespace Database\Factories;

use App\Enums\MonitoringAction as MonitoringActionType;
use App\Models\MonitoringAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonitoringAction>
 */
class MonitoringActionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'action' => MonitoringActionType::RunTask,
            'target' => 'quotes:expire',
            'job_class' => null,
            'count' => 1,
            'ip' => fake()->ipv4(),
        ];
    }
}
