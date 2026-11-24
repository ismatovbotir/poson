<?php

namespace Database\Factories;

use App\Enums\AttributeType;
use App\Models\Attribute;
use App\Models\AttributeOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttributeOption>
 */
class AttributeOptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $value = fake()->unique()->word();

        return [
            'attribute_id' => Attribute::factory()->state(['type' => AttributeType::Select]),
            'value' => ['uz' => $value, 'ru' => $value, 'en' => $value],
            'sort' => 0,
        ];
    }

    /**
     * With a display colour (plan 38).
     */
    public function withHex(string $hex = '#111111'): static
    {
        return $this->state(fn (array $attributes) => [
            'hex_color' => $hex,
        ]);
    }
}
