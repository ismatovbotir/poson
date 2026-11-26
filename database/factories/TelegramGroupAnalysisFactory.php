<?php

namespace Database\Factories;

use App\Enums\AnalysisStatus;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramGroupAnalysis>
 */
class TelegramGroupAnalysisFactory extends Factory
{
    /**
     * A queued debounce analysis.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'telegram_group_id' => TelegramGroup::factory()->tracked(),
            'status' => AnalysisStatus::Queued,
            'trigger' => TelegramGroupAnalysis::TRIGGER_DEBOUNCE,
        ];
    }

    public function done(string $summary = 'The client asks about a label printer.'): static
    {
        return $this->state(fn () => [
            'status' => AnalysisStatus::Done,
            'topic' => 'Label printer',
            'confidence' => 80,
            'summary' => $summary,
            'next_step' => 'Offer a setup visit.',
            'finished_at' => now(),
        ]);
    }
}
