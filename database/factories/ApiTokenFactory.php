<?php

namespace Database\Factories;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Tokens with a random plain value that is not kept. Use withPlainText()
 * to get a token the test can send (fake values only).
 *
 * @extends Factory<ApiToken>
 */
class ApiTokenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plain = ApiToken::PREFIX.Str::random(ApiToken::PLAIN_LENGTH);

        return [
            'name' => '1C '.fake()->word(),
            'token_hash' => ApiToken::hash($plain),
            'token_prefix' => substr($plain, 0, ApiToken::DISPLAY_PREFIX_LENGTH),
            'abilities' => [ApiToken::ABILITY_PRICES_WRITE],
            'created_by' => User::factory(),
            'expires_at' => null,
        ];
    }

    /**
     * Stored for the given plain token.
     */
    public function withPlainText(string $plain): static
    {
        return $this->state(fn (): array => [
            'token_hash' => ApiToken::hash($plain),
            'token_prefix' => substr($plain, 0, ApiToken::DISPLAY_PREFIX_LENGTH),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['revoked_at' => now()->subMinute()]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }

    /**
     * @param  list<string>  $abilities
     */
    public function abilities(array $abilities): static
    {
        return $this->state(fn (): array => ['abilities' => $abilities]);
    }
}
