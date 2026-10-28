<?php

namespace Database\Factories;

use App\Models\AiPromptOverride;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiPromptOverride>
 */
class AiPromptOverrideFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'field_key' => 'product.short_description',
            'prompt' => 'Write two sentences about {name} for the product card. Category: {category}.',
            'updated_by' => User::factory(),
        ];
    }

    public function forField(string $fieldKey): static
    {
        return $this->state(fn (array $attributes) => [
            'field_key' => $fieldKey,
        ]);
    }
}
