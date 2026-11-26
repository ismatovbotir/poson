<?php

namespace App\Http\Controllers\Tma;

use App\Http\Controllers\Controller;
use App\Models\TelegramUser;
use App\Rules\PhoneNumber;
use App\Support\ForeignPhoneLimit;
use App\Support\Telegram\MiniApp\BotUsername;
use App\Support\Telegram\MiniApp\TmaCart;
use App\Support\Telegram\MiniApp\TmaCartSummary;
use App\Support\Telegram\MiniApp\TmaSession;
use App\Support\Telegram\VerifiedTelegramPhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/**
 * Shared helpers of the signed-in Mini App screens (plan 20): the session
 * user, the cart switch and the page response with the layout data (cart
 * counter only while the cart is on, the bot link for "Contact a manager").
 */
abstract class TmaController extends Controller
{
    protected function user(Request $request): TelegramUser
    {
        return TmaSession::user($request);
    }

    protected function cartEnabled(): bool
    {
        return (bool) config('site.cart_enabled');
    }

    /**
     * 404 while the cart is off (catalog + requests only, plan 20 §5).
     */
    protected function ensureCart(): void
    {
        abort_unless($this->cartEnabled(), 404);
    }

    /**
     * The phone the user may order and send requests with (MA-9), or null:
     * the anonymous +888 number or an older registration of a recycled phone.
     */
    protected function verifiedPhone(TelegramUser $user): ?string
    {
        return VerifiedTelegramPhone::for($user);
    }

    /**
     * The "please contact a manager" screen for a phone that can't be used here.
     */
    protected function phoneScreen(): RedirectResponse
    {
        return redirect()->route('tma.unavailable', ['reason' => 'phone']);
    }

    /**
     * The name a request goes with: the registration name, else the Telegram first name.
     */
    protected function requestName(TelegramUser $user): string
    {
        $name = trim((string) ($user->name ?: trim($user->first_name.' '.$user->last_name)));

        return $name !== '' ? mb_substr($name, 0, 120) : 'Telegram';
    }

    /**
     * The site's public phone rules for the registration phone (TG-43 / TG-44),
     * checked last before a lead or an order is written; counts nothing:
     * PhoneNumber(public) (premium numbers; a foreign number while
     * site.foreign_phones_enabled is off) and the shared foreign-phone cap.
     * Returns the error message, or null. After the write, recordPhone().
     */
    protected function phoneRefusal(string $phone): ?string
    {
        $validator = Validator::make(['phone' => $phone], ['phone' => [new PhoneNumber(public: true)]]);

        if ($validator->fails()) {
            return (string) $validator->errors()->first('phone');
        }

        return ForeignPhoneLimit::reached($phone) ? __('validation.foreign_phone_limit') : null;
    }

    /**
     * Count a written lead or a placed order against the foreign-phone cap (Uzbek numbers never count).
     */
    protected function recordPhone(string $phone): void
    {
        ForeignPhoneLimit::record($phone);
    }

    /**
     * Count a hit on a per-user budget (outside any transaction); true when over it.
     */
    protected function overBudget(string $key, int $max, int $seconds): bool
    {
        return RateLimiter::hit($key, $seconds) > $max;
    }

    /**
     * The cart bar of the catalogue screens (plan 45, MA-45): the live cart
     * summary, or null while the cart is off.
     */
    protected function cartSummary(Request $request): ?TmaCartSummary
    {
        return $this->cartEnabled() ? TmaCart::summary($this->user($request)) : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function page(Request $request, string $view, array $data = []): Response
    {
        $user = $this->user($request);
        $bar = $this->cartEnabled() && ($data['cartBar'] ?? null) instanceof TmaCartSummary ? $data['cartBar'] : null;

        // A screen that already priced the cart passes its line count ("cartCount") or its
        // cart bar ("cartBar", plan 45) and saves the query.
        return response()->view($view, [
            ...$data,
            'cartCount' => $this->cartEnabled() ? ($bar?->count ?? (array_key_exists('cartCount', $data) ? $data['cartCount'] : TmaCart::lineCount($user))) : null,
            'cartBar' => $bar,
            'botLink' => BotUsername::link(),
        ]);
    }
}
