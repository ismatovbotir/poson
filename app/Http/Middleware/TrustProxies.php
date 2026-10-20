<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;

/**
 * Trusts only the proxies listed in config('app.trusted_proxies') (env TRUSTED_PROXIES).
 *
 * Read from config at request time rather than passed to trustProxies(at:) in
 * bootstrap/app.php: middleware is configured before the config is loaded, and
 * env() there would return nothing once the config is cached.
 */
class TrustProxies extends Middleware
{
    /**
     * @return list<string>|null
     */
    protected function proxies(): ?array
    {
        $proxies = config('app.trusted_proxies', []);

        return is_array($proxies) && $proxies !== [] ? array_values($proxies) : null;
    }
}
