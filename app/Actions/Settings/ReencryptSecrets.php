<?php

namespace App\Actions\Settings;

use App\Models\IntegrationSecret;
use App\Support\Secrets;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * Re-encrypt every integration secret with the current APP_KEY after a key
 * rotation (old key listed in APP_PREVIOUS_KEYS, which Laravel still uses to
 * decrypt). Values are never returned or logged; secrets that no key can
 * decrypt are left untouched and reported by key name. Not audited: the
 * values don't change. The Secrets cache is flushed at the end.
 */
class ReencryptSecrets
{
    /**
     * @return array{reencrypted: int, failed: list<string>}
     */
    public function handle(): array
    {
        $encrypter = IntegrationSecret::currentEncrypter();
        $reencrypted = 0;
        $failed = [];

        foreach (IntegrationSecret::query()->toBase()->orderBy('id')->get(['id', 'key', 'value']) as $row) {
            try {
                $plain = $encrypter->decrypt((string) $row->value, false);
            } catch (DecryptException) {
                $failed[] = (string) $row->key;

                continue;
            }

            // A mass update: the encrypted cast would see an unchanged value and skip the write.
            IntegrationSecret::query()->whereKey($row->id)->update(['value' => $encrypter->encrypt($plain, false)]);
            $reencrypted++;
        }

        Secrets::flush();

        return ['reencrypted' => $reencrypted, 'failed' => $failed];
    }
}
