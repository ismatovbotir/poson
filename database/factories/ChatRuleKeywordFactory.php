<?php

namespace Database\Factories;

use App\Enums\KeywordMatch;
use App\Models\ChatRule;
use App\Models\ChatRuleKeyword;
use App\Support\Chat\ChatText;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatRuleKeyword>
 */
class ChatRuleKeywordFactory extends Factory
{
    /**
     * Define the model's default state. "normalized" is derived from "phrase".
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $phrase = 'kalit'.fake()->unique()->numberBetween(1, 1_000_000);

        return [
            'chat_rule_id' => ChatRule::factory(),
            'locale' => null,
            'phrase' => $phrase,
            'normalized' => ChatText::normalize($phrase),
            'match_type' => KeywordMatch::Word,
            'weight' => 10,
        ];
    }

    public function phrase(string $phrase, KeywordMatch $type = KeywordMatch::Word, int $weight = 10, ?string $locale = null): static
    {
        return $this->state(fn () => [
            'phrase' => $phrase,
            'normalized' => ChatText::normalize($phrase),
            'match_type' => $type,
            'weight' => $weight,
            'locale' => $locale,
        ]);
    }
}
