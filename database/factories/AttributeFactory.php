<?php

namespace Database\Factories;

use App\Enums\AttributeType;
use App\Models\Attribute;
use App\Models\AttributeOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attribute>
 */
class AttributeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'code' => str_replace(' ', '_', $name).'_'.fake()->unique()->numberBetween(1, 999_999),
            'name' => ['uz' => ucfirst($name), 'ru' => 'RU '.$name, 'en' => 'EN '.$name],
            'unit' => null,
            'type' => AttributeType::Text,
            'is_filterable' => false,
            'sort' => 0,
        ];
    }

    public function number(?string $unit = 'mm'): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => AttributeType::Number,
            'unit' => $unit,
            'is_filterable' => true,
        ]);
    }

    public function boolean(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => AttributeType::Boolean,
            'is_filterable' => true,
        ]);
    }

    /**
     * A select attribute with options (values identical in every locale).
     *
     * @param  list<string>  $options
     */
    public function select(array $options = ['USB', 'RS232', 'Bluetooth']): static
    {
        return $this->withType(AttributeType::Select, $options);
    }

    /**
     * @param  list<string>  $options
     */
    public function multiselect(array $options = ['QR Code', 'DataMatrix', 'PDF417']): static
    {
        return $this->withType(AttributeType::Multiselect, $options);
    }

    public function filterable(bool $filterable = true): static
    {
        return $this->state(fn (array $attributes) => [
            'is_filterable' => $filterable,
        ]);
    }

    /**
     * @param  list<string>  $options
     */
    protected function withType(AttributeType $type, array $options): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
            'is_filterable' => true,
        ])->afterCreating(function (Attribute $attribute) use ($options): void {
            foreach (array_values($options) as $index => $value) {
                AttributeOption::factory()->for($attribute)->create([
                    'value' => ['uz' => $value, 'ru' => $value, 'en' => $value],
                    'sort' => $index + 1,
                ]);
            }
        });
    }
}
