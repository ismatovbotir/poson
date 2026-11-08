<?php

namespace Database\Seeders;

use App\Actions\Chat\InstallChatbotDefaults;
use Illuminate\Database\Seeder;

/**
 * The built-in chat bot rules (plan 28 C1), from
 * database/seeders/data/chatbot_defaults.php. Create-only and idempotent
 * (existing keys are never touched); the rules stay inert until the channel
 * switch is turned on. Same as `php artisan chatbot:install-defaults`.
 */
class ChatbotDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        app(InstallChatbotDefaults::class)->handle();
    }
}
