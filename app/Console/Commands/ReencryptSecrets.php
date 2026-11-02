<?php

namespace App\Console\Commands;

use App\Actions\Settings\ReencryptSecrets as ReencryptSecretsAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * After rotating APP_KEY: put the old key in APP_PREVIOUS_KEYS, deploy, run
 *
 *   php artisan secrets:reencrypt
 *
 * then remove the old key from APP_PREVIOUS_KEYS. Prints counts and key
 * names only, never values.
 */
#[Signature('secrets:reencrypt')]
#[Description('Re-encrypt the integration secrets (Settings → Integrations) with the current APP_KEY')]
class ReencryptSecrets extends Command
{
    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Re-encrypts the integration secrets (Settings → Integrations) with the current APP_KEY.
Run it once after rotating APP_KEY: put the old key in APP_PREVIOUS_KEYS, deploy, run this command, then remove the old key. It prints counts and key names only, never values.
Side effect: rewrites the encrypted secret values. Exits with failure if some secrets cannot be decrypted. Manual only, not scheduled.';

    /**
     * Execute the console command.
     */
    public function handle(ReencryptSecretsAction $reencrypt): int
    {
        $result = $reencrypt->handle();

        $this->components->info("Re-encrypted {$result['reencrypted']} integration secret(s) with the current APP_KEY.");

        if ($result['failed'] !== []) {
            $this->components->error('Could not decrypt (not in APP_KEY or APP_PREVIOUS_KEYS): '.implode(', ', $result['failed']).'. Enter these again in Settings → Integrations.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
