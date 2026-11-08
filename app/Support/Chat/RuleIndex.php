<?php

namespace App\Support\Chat;

use App\Models\ChatRule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * All active rules and keywords compiled into one plain array in the cache
 * (plan 28 §2.3, CB-2), so matching runs without a single database query.
 *
 *  - Cache key = "chatbot:rules:v{version}"; the version is a counter bumped
 *    by bump(), which waits for the surrounding transaction to commit
 *    (DB::afterCommit), so a rolled-back save never invalidates anything and
 *    a reader can never cache pre-commit data under the new version.
 *  - One key for every locale and channel: those filters run inside the array.
 *  - A miss rebuilds under Cache::lock; a second request waits briefly and
 *    reads the first one's result. Any cache or build failure returns null
 *    and the engine answers with the fallback reply, never a 500.
 *  - Caps: MAX_RULES rules, MAX_KEYWORDS per rule.
 *
 * Shape:
 *   rules:    id => {id, key, name, action, params, answer{locale=>text}, buttons, web, tg, menu, menu_sort, priority}
 *   keywords: list of {r: rule id, l: locale|null, n: normalized, t: match type, w: weight}
 *   menu:     rule ids in menu order
 *
 * @phpstan-type Compiled array{rules: array<int, array<string, mixed>>, keywords: list<array{r: int, l: ?string, n: string, t: string, w: int}>, menu: list<int>}
 */
class RuleIndex
{
    public const VERSION_KEY = 'chatbot:rules:version';

    public const LOCK_KEY = 'chatbot:rules:build';

    /**
     * @var array{version: int, data: Compiled}|null
     */
    private ?array $memo = null;

    /**
     * @return Compiled|null null when the cache or the build failed
     */
    public function load(): ?array
    {
        try {
            $version = self::version();

            // Per-instance copy, valid while the version is unchanged (one cache read, no rebuild).
            if ($this->memo !== null && $this->memo['version'] === $version) {
                return $this->memo['data'];
            }

            $key = self::keyFor($version);
            $cached = Cache::get($key);

            if (is_array($cached)) {
                $this->memo = ['version' => $version, 'data' => $cached];

                return $cached;
            }

            $data = Cache::lock(self::LOCK_KEY, (int) config('chatbot.cache_lock_seconds', 10))
                ->block((int) config('chatbot.cache_lock_wait_seconds', 3), function () use ($key): array {
                    $cached = Cache::get($key);

                    if (is_array($cached)) {
                        return $cached;
                    }

                    $compiled = $this->build();
                    Cache::put($key, $compiled, now()->addDay());

                    return $compiled;
                });

            $this->memo = ['version' => $version, 'data' => $data];

            return $data;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Invalidate the compiled rules once the current transaction commits
     * (immediately when there is none).
     */
    public static function bump(): void
    {
        DB::afterCommit(function (): void {
            try {
                Cache::add(self::VERSION_KEY, 1);
                Cache::increment(self::VERSION_KEY);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    public static function version(): int
    {
        $version = Cache::get(self::VERSION_KEY);

        return is_numeric($version) ? max(1, (int) $version) : 1;
    }

    public static function keyFor(int $version): string
    {
        return "chatbot:rules:v{$version}";
    }

    /**
     * Compile from the database: one query for the rules, one for their
     * keywords.
     *
     * @return Compiled
     */
    public function build(): array
    {
        $rules = ChatRule::query()
            ->where('is_active', true)
            ->with(['keywords' => fn ($query) => $query->orderBy('id')])
            ->orderBy('id')
            ->limit((int) config('chatbot.max_rules', 500))
            ->get();

        $compiled = ['rules' => [], 'keywords' => [], 'menu' => []];
        $menu = [];
        $keywordCap = (int) config('chatbot.max_keywords_per_rule', 50);

        foreach ($rules as $rule) {
            $compiled['rules'][$rule->id] = [
                'id' => $rule->id,
                'key' => $rule->key,
                'name' => $rule->name,
                'action' => $rule->action->value,
                'params' => is_array($rule->params) ? $rule->params : [],
                'answer' => array_map('strval', $rule->getTranslations('answer')),
                'buttons' => is_array($rule->buttons) ? $rule->buttons : [],
                'web' => $rule->on_web,
                'tg' => $rule->on_telegram,
                'menu' => $rule->in_menu,
                'menu_sort' => $rule->menu_sort,
                'priority' => $rule->priority,
            ];

            foreach ($rule->keywords->take($keywordCap) as $keyword) {
                if ($keyword->normalized === '') {
                    continue;
                }

                $compiled['keywords'][] = [
                    'r' => $rule->id,
                    'l' => $keyword->locale,
                    'n' => $keyword->normalized,
                    't' => $keyword->match_type->value,
                    'w' => $keyword->weight,
                ];
            }

            if ($rule->in_menu) {
                $menu[$rule->id] = [$rule->menu_sort, $rule->id];
            }
        }

        uasort($menu, fn (array $a, array $b): int => $a <=> $b);
        $compiled['menu'] = array_values(array_map(fn (array $row): int => $row[1], $menu));

        return $compiled;
    }
}
