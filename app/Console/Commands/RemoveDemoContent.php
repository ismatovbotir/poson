<?php

namespace App\Console\Commands;

use App\Actions\Maintenance\RemoveDemoContent as RemoveDemoContentAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Remove the launch demo products, news and banners (plan 15 A6) before
 * real products go in. Lists what it would remove; --dry-run stops there.
 * In production --force is required; elsewhere it asks for confirmation
 * (or takes --force). Only rows the seeders recorded (setting launch_demo_content)
 * are removed; edited ones are skipped unless --include-edited.
 *
 *   php artisan demo:remove --dry-run
 *   php artisan demo:remove --force
 */
#[Signature('demo:remove
    {--force : Remove without asking (required in production)}
    {--dry-run : Only list what would be removed}
    {--include-edited : Also remove demo rows edited after they were seeded}
    {--user= : Staff user id running it (for the log)}')]
#[Description('Delete the demo products, news and banners seeded at launch')]
class RemoveDemoContent extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RemoveDemoContentAction $remove): int
    {
        $found = $remove->find((bool) $this->option('include-edited'));

        $this->components->twoColumnDetail('Demo products', (string) $found['products']->count());
        $this->components->twoColumnDetail('Demo news posts', (string) $found['news']->count());
        $this->components->twoColumnDetail('Demo banners', (string) $found['banners']->count());

        foreach ($found['skipped'] as $label) {
            $this->components->warn("Skipped (edited after seeding): {$label}. Use --include-edited to remove it too.");
        }

        foreach ($found['products'] as $product) {
            $this->line("  {$product->sku}", verbosity: 'v');
        }

        if ($this->option('dry-run')) {
            $this->components->info('Dry run: nothing removed.');

            return self::SUCCESS;
        }

        if ($found['products']->isEmpty() && $found['news']->isEmpty() && $found['banners']->isEmpty()) {
            $this->components->info('No demo content left.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            if ($this->laravel->isProduction()) {
                $this->components->error('In production run it with --force.');

                return self::FAILURE;
            }

            if (! $this->components->confirm('Permanently delete this demo content?')) {
                $this->components->warn('Nothing removed.');

                return self::FAILURE;
            }
        }

        $removed = $remove->handle((bool) $this->option('include-edited'), $this->option('user') !== null ? (int) $this->option('user') : null);

        $this->components->info("Removed {$removed['products']} product(s), {$removed['news']} news post(s) and {$removed['banners']} banner(s).");

        return self::SUCCESS;
    }
}
