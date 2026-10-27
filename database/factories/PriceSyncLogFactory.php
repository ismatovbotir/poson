<?php

namespace Database\Factories;

use App\Enums\PriceSyncStatus;
use App\Models\ApiToken;
use App\Models\PriceSyncLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceSyncLog>
 */
class PriceSyncLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'api_token_id' => ApiToken::factory(),
            'token_name' => '1C',
            'ip' => fake()->ipv4(),
            'status' => PriceSyncStatus::Success,
            'received' => 1,
            'updated' => 1,
            'unchanged' => 0,
            'unknown' => 0,
            'invalid' => 0,
            'unknown_codes' => [],
            'results' => [[
                'product_id' => 1,
                'code' => 'ZD421',
                'status' => 'updated',
                'old' => ['retail' => 100_000_00, 'dealer' => null, 'wholesale' => null],
                'new' => ['retail' => 120_000_00, 'dealer' => null, 'wholesale' => null],
            ]],
            'error' => null,
            'duration_ms' => 12,
            'created_at' => now(),
        ];
    }

    public function rejected(string $error = 'too_many_items'): static
    {
        return $this->state(fn (): array => [
            'status' => PriceSyncStatus::Rejected,
            'received' => 0,
            'updated' => 0,
            'results' => [],
            'error' => $error,
        ]);
    }

    /**
     * @param  list<string>  $codes
     */
    public function withUnknown(array $codes): static
    {
        return $this->state(fn (): array => [
            'status' => PriceSyncStatus::Partial,
            'received' => count($codes),
            'updated' => 0,
            'unknown' => count($codes),
            'unknown_codes' => $codes,
            'results' => array_map(fn (string $code): array => ['code' => $code, 'status' => 'unknown'], $codes),
        ]);
    }
}
