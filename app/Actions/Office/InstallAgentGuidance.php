<?php

namespace App\Actions\Office;

use App\Enums\AgentRole;
use App\Models\AgentProfile;
use App\Models\AgentProfileVersion;
use App\Support\Office\AgentGuidance;
use Illuminate\Support\Facades\DB;

/**
 * The default structured guidance and examples of the four Virtual office
 * agents (plan 46 §3, T4), for LaunchSeeder. For each role whose versions
 * have no guidance yet, ONE new unpublished version is created: the
 * published text (instructions, rules, knowledge, model, limit) plus the
 * guidance and examples of database/seeders/data/office_guidance.php.
 * Create-only and idempotent: it never publishes, never changes a version
 * and never runs for a role that already has guidance in any version. The
 * {placeholders} in the service facts are inert until the owner replaces
 * them (publishing is blocked until then, PublishAgentProfileVersion).
 * The new version is NOT meant to go live as it is: it is unpublished on purpose and the owner edits the
 * placeholders (and reviews the examples) before publishing. The published version's own examples are kept:
 * they come first, the seeded ones fill what is left of the caps (6 good, 4 bad, examples_max in total).
 */
class InstallAgentGuidance
{
    /**
     * @return int Roles that got a guidance version
     */
    public function handle(): int
    {
        $data = (array) require database_path('seeders/data/office_guidance.php');
        $created = 0;

        foreach (AgentRole::profiles() as $role) {
            $created += DB::transaction(function () use ($role, $data): int {
                $profile = AgentProfile::forRole($role);
                $base = $profile?->currentVersion ?? $profile?->versions()->orderByDesc('version')->first();

                if (! $profile instanceof AgentProfile || ! $base instanceof AgentProfileVersion || $profile->versions()->whereNotNull('guidance')->exists()) {
                    return 0;
                }

                $locked = AgentProfile::query()->whereKey($profile->id)->lockForUpdate()->first();

                if ($locked === null || $locked->versions()->whereNotNull('guidance')->exists()) {
                    return 0;
                }

                $seed = (array) ($data[$role->value] ?? []);

                $locked->versions()->create([
                    'version' => (int) $locked->versions()->max('version') + 1,
                    'instructions' => $base->instructions,
                    'knowledge' => $base->knowledge,
                    'rules' => $base->rules,
                    'examples' => self::mergeExamples((array) $base->examples, (array) ($seed['examples'] ?? [])),
                    'guidance' => AgentGuidance::parse($seed['guidance'] ?? [], $role)->toArray(),
                    'model' => $base->model,
                    'max_tokens' => $base->max_tokens,
                    'change_note' => 'Default guidance (seed, not published).',
                ]);

                return 1;
            });
        }

        return $created;
    }

    /**
     * @param  list<array<string, mixed>>  $base  The published version's examples (an old one without a kind is good)
     * @param  list<array<string, mixed>>  $seed
     * @return list<array<string, mixed>>
     */
    private static function mergeExamples(array $base, array $seed): array
    {
        $caps = ['good' => (int) config('office.education.guidance.examples_good_max', 6), 'bad' => (int) config('office.education.guidance.examples_bad_max', 4), 'route' => PHP_INT_MAX];
        $total = (int) config('office.education.examples_max', 10);
        $count = ['good' => 0, 'bad' => 0, 'route' => 0];
        $merged = [];

        foreach ([...$base, ...$seed] as $example) {
            $example = (array) $example;
            $kind = in_array($example['kind'] ?? null, ['good', 'bad', 'route'], true) ? $example['kind'] : 'good';

            if (count($merged) >= $total || $count[$kind] >= $caps[$kind]) {
                continue;
            }

            $count[$kind]++;
            $merged[] = ['kind' => $kind, 'language' => $example['language'] ?? 'uz'] + $example;
        }

        return $merged;
    }
}
