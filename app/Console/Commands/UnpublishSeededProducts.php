<?php

namespace App\Console\Commands;

use App\Actions\Catalog\UnpublishSeededProducts as UnpublishSeededProductsAction;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Rollback of the plan 16 direct publishing (P7): untouched seeded products go
 * back to drafts, empty import categories to inactive. Needs an Admin user
 * (--user=ID) for the audit trail; in production --force is required.
 *
 *   php artisan catalog:unpublish-seeded --user=1 --force
 */
#[Signature('catalog:unpublish-seeded
    {--user= : Id of the Admin user running it (audited)}
    {--force : Run without asking (required in production)}')]
#[Description('Return the untouched seeded products to drafts (rollback of the direct publishing)')]
class UnpublishSeededProducts extends Command
{
    public function handle(UnpublishSeededProductsAction $unpublish): int
    {
        $user = $this->option('user') !== null ? User::query()->find((int) $this->option('user')) : null;

        if ($user === null || ! $user->hasRole(Role::Admin)) {
            $this->components->error('Pass --user=<id of an Admin user>.');

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            if ($this->laravel->isProduction()) {
                $this->components->error('In production run it with --force.');

                return self::FAILURE;
            }

            if (! $this->components->confirm('Return the untouched seeded products to drafts?')) {
                $this->components->warn('Nothing changed.');

                return self::FAILURE;
            }
        }

        $result = $unpublish->handle($user);

        $this->components->info("Returned {$result['products']} product(s) to drafts and deactivated ".count($result['categories']).' categor'.(count($result['categories']) === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }
}
