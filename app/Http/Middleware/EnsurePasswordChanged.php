<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forced password change (alias "admin.password-changed", plan 24 A1): a user
 * with a temporary password reaches only admin.account.password and logout.
 * Runs after "admin", in every admin route except the password screen, and is
 * Livewire persistent middleware, so a tab opened earlier is refused too. The
 * user stays signed in: reloading the page leads to the password screen.
 */
class EnsurePasswordChanged
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->mustChangePassword()) {
            return $next($request);
        }

        if (Livewire::isLivewireRequest() || $request->expectsJson()) {
            abort(403);
        }

        return redirect()->route('admin.account.password');
    }
}
