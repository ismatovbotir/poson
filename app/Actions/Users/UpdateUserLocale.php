<?php

namespace App\Actions\Users;

use App\Models\User;
use InvalidArgumentException;

/**
 * Save the admin UI language of a user.
 */
class UpdateUserLocale
{
    public function handle(User $user, string $locale): void
    {
        if (! in_array($locale, config('app.supported_locales', []), true)) {
            throw new InvalidArgumentException("Unsupported locale [{$locale}].");
        }

        $user->forceFill(['locale' => $locale])->save();
    }
}
