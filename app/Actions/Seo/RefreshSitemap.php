<?php

namespace App\Actions\Seo;

use App\Support\Site\Sitemap;
use Illuminate\Support\Facades\Cache;

/**
 * Rebuilds /sitemap.xml now: build it on APP_URL
 * (CanonicalHost, never the request host), put it over the cached one (never forgetting first, so the public site is not cold during the build).
 *
 * Runs under the global non-blocking build lock, so it never overlaps another
 * build (the public cold start or a second refresh). Authorization and the
 * rate limit are the caller's job (the admin screen).
 */
class RefreshSitemap
{
    public function __construct(private readonly Sitemap $sitemap) {}

    /**
     * @return array{xml: string, generated_at: string, expires_at: string, counts: array<string, int>}|null null while another build holds the lock
     */
    public function handle(): ?array
    {
        $lock = Cache::lock(Sitemap::BUILD_LOCK, Sitemap::LOCK_SECONDS);

        if (! $lock->get()) {
            return null;
        }

        try {
            return $this->sitemap->store($this->sitemap->generate());
        } finally {
            $lock->release();
        }
    }
}
