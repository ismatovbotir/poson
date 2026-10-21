<?php

namespace Database\Factories;

use App\Models\IntegrationSecret;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Fake values only (never a real key).
 *
 * @extends Factory<IntegrationSecret>
 */
class IntegrationSecretFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $value = 'fake-'.Str::random(32);

        return [
            'key' => 'anthropic.api_key',
            'value' => $value,
            'hint' => IntegrationSecret::hintFor($value),
            'updated_by' => null,
        ];
    }

    /**
     * A secret with a given key and value (the hint follows the value).
     */
    public function withValue(string $key, #[\SensitiveParameter] string $value): static
    {
        return $this->state(fn (): array => [
            'key' => $key,
            'value' => $value,
            'hint' => IntegrationSecret::hintFor($value),
        ]);
    }
}
