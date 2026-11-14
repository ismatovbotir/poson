<?php

namespace App\Console\Commands;

use App\Actions\Careers\InstallSampleVacancies;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use InvalidArgumentException;

/**
 * Installs the sample vacancies (plan 31 §12, CR-15). Not part of LaunchSeeder.
 */
#[Signature('careers:install-samples {--draft : Install unpublished (outside production the samples are published by default; in production they are always drafts unless --publish)} {--publish : Publish the samples in production (needs --force when not interactive)} {--force : Run in production without the confirmation}')]
#[Description('Create the sample job vacancies (uz/ru/en) that do not exist yet; drafts by default in production')]
class CareersInstallSamples extends Command
{
    use ConfirmableTrait;

    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Creates the sample vacancies from database/seeders/data/vacancies_sample.php through the same checks as the admin form (plain text, brand-safe wording, unique slugs).
Safe to run again: a vacancy whose Uzbek slug already exists is skipped and never changed. In production the vacancies are installed as drafts (unpublished) unless --publish is given, and the command asks for confirmation (use --force when not interactive). Publish them from Admin → Careers → Vacancies after review.
Side effect: creates vacancies and flushes the sitemap and the careers menu cache. Not scheduled and not part of LaunchSeeder; run it by hand once if you want the examples.';

    public function handle(InstallSampleVacancies $install): int
    {
        if ($this->option('draft') && $this->option('publish')) {
            $this->components->error('Use either --draft or --publish, not both.');

            return self::FAILURE;
        }

        if (! $this->confirmToProceed()) {
            $this->components->warn('Nothing installed.');

            return self::FAILURE;
        }

        $draft = (bool) $this->option('draft') || ($this->laravel->isProduction() && ! $this->option('publish'));

        try {
            $result = $install->handle(! $draft);
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("{$result['created']} vacancy(ies) created".($draft ? ' as drafts' : '').", {$result['skipped']} already existed, {$result['failed']} rejected by validation.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
