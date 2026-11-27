<?php

namespace Database\Factories;

use App\Enums\AgentRole;
use App\Enums\DraftFeedbackReason;
use App\Enums\DraftOutcome;
use App\Models\BusinessChat;
use App\Models\BusinessChatAnalysis;
use App\Models\OfficeDraftFeedback;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfficeDraftFeedback>
 */
class OfficeDraftFeedbackFactory extends Factory
{
    /**
     * A chat draft that was used as written.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_chat_id' => BusinessChat::factory()->client(),
            'business_chat_analysis_id' => fn (array $attributes) => BusinessChatAnalysis::factory()->done()->create([
                'business_chat_id' => $attributes['business_chat_id'],
            ])->id,
            'role' => AgentRole::Sales,
            'outcome' => DraftOutcome::Used,
            'draft_text' => 'Hello, which label size do you need?',
        ];
    }

    public function edited(string $finalText = 'Hello, tell us the label size and quantity.'): static
    {
        return $this->state(fn () => [
            'outcome' => DraftOutcome::Edited,
            'reason' => DraftFeedbackReason::Tone,
            'final_text' => $finalText,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'outcome' => DraftOutcome::Rejected,
            'reason' => DraftFeedbackReason::WrongFacts,
        ]);
    }
}
