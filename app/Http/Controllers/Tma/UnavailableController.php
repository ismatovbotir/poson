<?php

namespace App\Http\Controllers\Tma;

use App\Http\Controllers\Controller;
use App\Support\Telegram\MiniApp\BotUsername;
use Illuminate\Http\Response;

/**
 * GET /tma/{locale}/unavailable/{reason} (plan 20 §3): the static screens
 * when the Mini App can't be used. No session data, no personal data. The
 * bot link (openTelegramLink) appears only with a stored, valid username.
 */
class UnavailableController extends Controller
{
    /**
     * register: not registered in the bot; banned: neutral; blocked: the bot is
     * blocked; expired: reopen the app; disabled: the Mini App is off;
     * browser: opened outside Telegram or cookies refused; phone: the
     * registration phone can't place orders here (contact a manager).
     */
    public const REASONS = ['register', 'banned', 'blocked', 'expired', 'disabled', 'browser', 'phone'];

    public function __invoke(string $reason): Response
    {
        return response()->view('tma.unavailable', [
            'reason' => $reason,
            'botLink' => match ($reason) {
                'register' => BotUsername::link('tma'),
                'banned', 'disabled', 'expired' => null,
                default => BotUsername::link(),
            },
        ]);
    }
}
