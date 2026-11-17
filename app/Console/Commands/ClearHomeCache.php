<?php

namespace App\Console\Commands;

use App\Support\Site\HomeCache;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Drops the cached home page data of every locale (deploy step, plan 34).
 */
#[Signature('site:home-cache-clear')]
#[Description('Drop the cached home page data of all locales')]
class ClearHomeCache extends Command
{
    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Bumps the home page cache version, so the next visit of every locale (/uz, /ru, /en) rebuilds the page data (banners, categories, popular products, brands, news, FAQ, hero stats, JSON-LD) from the database. The old entries are not read any more and expire by themselves within 4 hours.
Run it after a deploy that changes the home page markup or copy, or when the page shows stale data. Editing products, categories, brands, banners, news, FAQ or the company settings in the admin does this automatically. Nothing else is cleared (no sessions, no other caches). Not scheduled.';

    public function handle(): int
    {
        HomeCache::bump();

        $this->info('Home page cache cleared.');

        return self::SUCCESS;
    }
}
