<?php

namespace App\Actions\Settings;

use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\CompanySettings;
use App\Support\Site\LlmsTxt;
use App\Support\Site\Sitemap;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Open or close the site for search engines and AI crawlers (plan 15 C,
 * Settings → Indexing). Admin only: the user must pass manageArea for
 * SettingsArea::Indexing (checked here too, not only in the screen); the
 * screen also requires a recent password confirmation.
 *
 * In one transaction (row locked): nothing happens when the state is
 * unchanged; otherwise the search_indexing row (group seo) is written as a
 * boolean and audited (area indexing, action opened / closed: the new
 * state, the old one being its opposite). After commit the CompanySettings,
 * sitemap and llms.txt caches are flushed.
 */
class UpdateSearchIndexing
{
    /**
     * @return bool whether the state changed
     *
     * @throws AuthorizationException
     */
    public function handle(bool $open, User $user, ?string $ip): bool
    {
        if (! $user->can('manageArea', [Setting::class, SettingsArea::Indexing])) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($open, $user, $ip): bool {
            $row = Setting::query()->where('key', CompanySettings::INDEXING_KEY)->lockForUpdate()->first();

            if (($row?->value === true) === $open) {
                return false;
            }

            Setting::query()->updateOrCreate(
                ['key' => CompanySettings::INDEXING_KEY],
                ['group' => SettingsArea::Indexing->group(), 'value' => $open],
            );

            SettingsAudit::record(SettingsArea::Indexing, CompanySettings::INDEXING_KEY, $open ? AuditAction::Opened : AuditAction::Closed, $user, $ip);

            DB::afterCommit(function (): void {
                CompanySettings::flush();
                Sitemap::flush();
                LlmsTxt::flush();
            });

            return true;
        });
    }
}
