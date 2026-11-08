<?php

namespace App\Console\Commands;

use App\Actions\Chat\InstallChatbotDefaults;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Creates the built-in chat bot rules (plan 28 C1); also run by LaunchSeeder.
 */
#[Signature('chatbot:install-defaults')]
#[Description('Create the built-in chat bot rules (greeting, FAQ, delivery, payment, ...) that do not exist yet')]
class ChatbotInstallDefaults extends Command
{
    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Creates the built-in (system) chat bot rules with their keywords and placeholder answers in Uzbek, Russian and English.
Safe to run again: a rule whose key already exists is never changed, so edits made in Admin are kept. The rules do nothing until the Telegram chat bot switch is turned on.
Side effect: creates chat rules and keywords and refreshes the rule cache. Not scheduled; run once at launch (LaunchSeeder does it) and after adding new defaults.';

    /**
     * Execute the console command.
     */
    public function handle(InstallChatbotDefaults $install): int
    {
        $result = $install->handle();

        $this->components->info("{$result['created']} rule(s) created, {$result['skipped']} already existed.");

        return self::SUCCESS;
    }
}
