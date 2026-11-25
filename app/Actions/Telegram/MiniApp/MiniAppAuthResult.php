<?php

namespace App\Actions\Telegram\MiniApp;

use App\Models\TelegramUser;

/**
 * The outcome of AuthenticateMiniAppUser: the signed-in user, or the reason
 * screen to show (UnavailableController::REASONS), in the user's locale.
 */
final readonly class MiniAppAuthResult
{
    public function __construct(
        public string $locale,
        public ?TelegramUser $user = null,
        public ?string $reason = null,
    ) {}

    public function isSignedIn(): bool
    {
        return $this->user !== null;
    }
}
