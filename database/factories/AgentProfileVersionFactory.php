<?php

namespace Database\Factories;

use App\Models\AgentProfile;
use App\Models\AgentProfileVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentProfileVersion>
 */
class AgentProfileVersionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_profile_id' => AgentProfile::factory(),
            'version' => 1,
            'instructions' => 'Summarise the conversation for the manager.',
            'knowledge' => null,
            'rules' => null,
            'examples' => [],
            'model' => 'claude-sonnet-5-5',
            'max_tokens' => 900,
        ];
    }
}
