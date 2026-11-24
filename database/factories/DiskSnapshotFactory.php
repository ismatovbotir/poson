<?php

namespace Database\Factories;

use App\Enums\DiskMeasureStatus;
use App\Enums\DiskTarget;
use App\Models\DiskSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiskSnapshot>
 */
class DiskSnapshotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'target' => DiskTarget::ProjectTotal,
            'taken_on' => now('Asia/Tashkent')->toDateString(),
            'size_bytes' => 1024 ** 3,
            'file_count' => 1000,
            'status' => DiskMeasureStatus::Ok,
            'duration_ms' => 1500,
            'measured_at' => now(),
        ];
    }
}
