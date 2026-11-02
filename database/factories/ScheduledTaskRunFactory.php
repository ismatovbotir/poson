<?php

namespace Database\Factories;

use App\Enums\TaskRunStatus;
use App\Enums\TaskRunTrigger;
use App\Models\ScheduledTaskRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduledTaskRun>
 */
class ScheduledTaskRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $started = now()->subMinutes(fake()->numberBetween(1, 600));
        $duration = fake()->numberBetween(20, 4000);

        return [
            'task' => fake()->randomElement(['quotes:expire', 'mcp:sweep', 'analytics:aggregate']),
            'trigger' => TaskRunTrigger::Schedule,
            'status' => TaskRunStatus::Success,
            'started_at' => $started,
            'finished_at' => $started->copy()->addMilliseconds($duration),
            'duration_ms' => $duration,
            'exit_code' => 0,
            'error' => null,
            'output' => null,
            'user_id' => null,
        ];
    }

    public function failed(string $error = 'Boom'): static
    {
        return $this->state(['status' => TaskRunStatus::Failed, 'exit_code' => 1, 'error' => $error]);
    }

    public function running(): static
    {
        return $this->state(['status' => TaskRunStatus::Running, 'finished_at' => null, 'duration_ms' => null, 'exit_code' => null]);
    }
}
