<?php

namespace Database\Factories;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * A note on a (new) lead without an author; subject() another subject, by() an author.
 *
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_type' => 'lead',
            'subject_id' => Lead::factory(),
            'user_id' => null,
            'type' => ActivityType::Note,
            'body' => fake()->sentence(),
            'meta' => null,
            'created_at' => now(),
        ];
    }

    public function subject(Model $subject): static
    {
        return $this->state(fn (array $attributes) => [
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ]);
    }

    public function by(User $user): static
    {
        return $this->state(fn (array $attributes) => ['user_id' => $user->id]);
    }

    public function type(ActivityType $type): static
    {
        return $this->state(fn (array $attributes) => ['type' => $type]);
    }
}
