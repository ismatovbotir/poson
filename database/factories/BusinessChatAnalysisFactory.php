<?php

namespace Database\Factories;

use App\Enums\AgentRole;
use App\Enums\AnalysisStatus;
use App\Models\BusinessChat;
use App\Models\BusinessChatAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessChatAnalysis>
 */
class BusinessChatAnalysisFactory extends Factory
{
    /**
     * A queued debounce analysis.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_chat_id' => BusinessChat::factory()->client(),
            'status' => AnalysisStatus::Queued,
            'trigger' => BusinessChatAnalysis::TRIGGER_DEBOUNCE,
        ];
    }

    public function done(string $summary = 'The client asks about a label printer.'): static
    {
        return $this->state(fn () => [
            'status' => AnalysisStatus::Done,
            'role' => AgentRole::Sales,
            'topic' => 'Label printer',
            'confidence' => 80,
            'summary' => $summary,
            'next_step' => 'Offer a setup visit.',
            'finished_at' => now(),
        ]);
    }
}
