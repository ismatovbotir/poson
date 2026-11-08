<?php

namespace App\Actions\Chat;

use App\Models\ChatRule;
use App\Support\Chat\RuleIndex;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Create the built-in (system) chat rules from
 * database/seeders/data/chatbot_defaults.php (plan 28 C1). Create-only and
 * idempotent: a rule whose key already exists is left as it is (the owner may
 * have edited it), so running it again changes nothing. The rules are inert
 * until the channel switch (chatbot.telegram_enabled) is turned on.
 */
class InstallChatbotDefaults
{
    public function __construct(private readonly SaveChatRule $save) {}

    /**
     * @return array{created: int, skipped: int}
     */
    public function handle(): array
    {
        $created = 0;
        $skipped = 0;

        foreach ((array) require database_path('seeders/data/chatbot_defaults.php') as $definition) {
            if (ChatRule::query()->where('key', $definition['key'])->exists()) {
                $skipped++;

                continue;
            }

            try {
                $this->save->installSystem($this->input($definition));
                $created++;
            } catch (UniqueConstraintViolationException) {
                // A concurrent run created this key first.
                $skipped++;
            }
        }

        if ($created > 0) {
            RuleIndex::bump();
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function input(array $definition): array
    {
        $menu = $definition['menu'] ?? null;
        $params = (array) ($definition['params'] ?? []);

        if ($menu !== null) {
            $params['label'] = $definition['label'];
        }

        $keywords = [];

        foreach ($definition['keywords'] as $entry) {
            [$text, $weight] = is_array($entry) ? [$entry[0], $entry[1]] : [$entry, 10];
            $prefix = str_ends_with($text, '*');
            $text = rtrim($text, '*');

            $keywords[] = [
                'phrase' => $text,
                'match_type' => $prefix ? 'prefix' : (str_contains($text, ' ') ? 'phrase' : 'word'),
                'weight' => $weight,
                'locale' => null,
            ];
        }

        return [
            'key' => $definition['key'],
            'name' => $definition['name'],
            'action' => $definition['action'],
            'params' => $params,
            'answer' => $definition['answer'] ?? [],
            'keywords' => $keywords,
            'in_menu' => $menu !== null,
            'menu_sort' => $menu ?? 0,
            'priority' => $definition['priority'] ?? 0,
            'on_web' => true,
            'on_telegram' => true,
            'is_active' => true,
        ];
    }
}
