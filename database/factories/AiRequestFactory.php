<?php

namespace Database\Factories;

use App\Enums\AiErrorCode;
use App\Enums\AiFeature;
use App\Enums\AiProvider;
use App\Enums\AiRequestStatus;
use App\Models\AiRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A succeeded text generation for a product name. States: pending(),
 * failed(), rejected(), translate(), costing(), createdAt().
 *
 * @extends Factory<AiRequest>
 */
class AiRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'feature' => AiFeature::TextGenerate,
            'provider' => AiProvider::Anthropic,
            'model' => 'claude-opus-5-5',
            'field_key' => 'product.short_description',
            'status' => AiRequestStatus::Succeeded,
            'prompt' => 'Write a short description of the product.',
            'response' => ['uz' => 'Yorliq printeri', 'ru' => 'Принтер этикеток', 'en' => 'Label printer'],
            'brand_warnings' => [],
            'input_tokens' => 800,
            'output_tokens' => 200,
            'cost_micro_usd' => 5400,
            'duration_ms' => 2100,
            'started_at' => now(),
            'finished_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => AiRequestStatus::Pending,
            'response' => null,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_micro_usd' => 0,
            'duration_ms' => null,
            'started_at' => null,
            'finished_at' => null,
        ]);
    }

    public function failed(AiErrorCode $code = AiErrorCode::ProviderError): static
    {
        return $this->state(fn (): array => [
            'status' => AiRequestStatus::Failed,
            'error_code' => $code,
            'response' => null,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_micro_usd' => 0,
        ]);
    }

    public function rejected(AiErrorCode $code = AiErrorCode::QuotaExceeded): static
    {
        return $this->failed($code)->state(fn (): array => ['status' => AiRequestStatus::Rejected, 'started_at' => null]);
    }

    public function translate(string $from = 'uz'): static
    {
        return $this->state(fn (): array => ['feature' => AiFeature::TextTranslate, 'source_locale' => $from]);
    }

    public function costing(int $microUsd): static
    {
        return $this->state(fn (): array => ['cost_micro_usd' => $microUsd]);
    }

    public function createdAt(mixed $moment): static
    {
        return $this->state(fn (): array => ['created_at' => $moment, 'updated_at' => $moment]);
    }
}
