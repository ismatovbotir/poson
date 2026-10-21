<?php

namespace Database\Factories;

use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Models\SettingsAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SettingsAudit>
 */
class SettingsAuditFactory extends Factory
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
            'area' => SettingsArea::Contacts,
            'key' => 'email',
            'action' => AuditAction::Updated,
            'ip' => fake()->ipv4(),
        ];
    }
}
