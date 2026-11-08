<?php

namespace Database\Factories;

use App\Enums\ChatAction;
use App\Models\ChatRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An active "answer" rule on both channels, not in the menu. Keywords are
 * added by the tests (ChatRuleKeywordFactory), or use withKeyword().
 *
 * @extends Factory<ChatRule>
 */
class ChatRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 1_000_000);

        return [
            'key' => "rule_{$number}",
            'name' => "Rule {$number}",
            'action' => ChatAction::Answer,
            'params' => null,
            'answer' => ['uz' => "Javob {$number}", 'ru' => "Ответ {$number}", 'en' => "Answer {$number}"],
            'buttons' => null,
            'on_web' => true,
            'on_telegram' => true,
            'in_menu' => false,
            'menu_sort' => 0,
            'priority' => 0,
            'is_active' => true,
            'is_system' => false,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function system(): static
    {
        return $this->state(fn () => ['is_system' => true]);
    }

    public function inMenu(int $sort = 1): static
    {
        return $this->state(fn () => ['in_menu' => true, 'menu_sort' => $sort]);
    }

    public function webOnly(): static
    {
        return $this->state(fn () => ['on_telegram' => false]);
    }
}
