<?php

namespace Database\Factories;

use App\Enums\McpAuthType;
use App\Enums\McpCallStatus;
use App\Models\McpCall;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<McpCall>
 */
class McpCallFactory extends Factory
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
            'auth_type' => McpAuthType::Token,
            'api_token_id' => null,
            'client_name' => null,
            'tool' => 'get_my_access',
            'is_write' => false,
            'is_publish' => false,
            'status' => McpCallStatus::Ok,
            'arguments' => [],
            'before' => null,
            'duration_ms' => fake()->numberBetween(5, 400),
            'ip' => fake()->ipv4(),
        ];
    }

    public function status(McpCallStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    /**
     * A write call with a before-snapshot (for "View before").
     *
     * @param  array<string, mixed>  $before
     */
    public function write(array $before = ['title' => ['uz' => 'Eski sarlavha']]): static
    {
        return $this->state(fn (): array => ['tool' => 'update_post', 'is_write' => true, 'before' => $before]);
    }

    /**
     * A successful create_post call that published (or scheduled) the given post.
     */
    public function publishOf(Model $post): static
    {
        return $this->state(fn (): array => [
            'tool' => 'create_post',
            'is_write' => true,
            'is_publish' => true,
            'status' => McpCallStatus::Ok,
            'subject_type' => $post->getMorphClass(),
            'subject_id' => $post->getKey(),
        ]);
    }
}
