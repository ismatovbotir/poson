<?php

namespace Database\Factories;

use App\Enums\AgentRole;
use App\Models\AgentProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentProfile>
 */
class AgentProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'role' => AgentRole::Sales,
            'use_site_knowledge' => false,
        ];
    }
}
