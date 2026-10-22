<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\FaqItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * First FAQ set (plan 04 T12/T13) from database/seeders/data/faq.php: the
 * general FAQ and the FAQ of top-level categories (by slug). Real content:
 * DatabaseSeeder runs it everywhere, and production runs it once at the
 * first launch (`db:seed --class=FaqSeeder --force`).
 *
 * Idempotent per owner: an owner (the general FAQ or a category) is seeded
 * only while it has no FAQ items at all. Once it has any, the admin owns that
 * list, so reworded or deleted items never come back. Items marked
 * 'confirm' => true (facts still to be confirmed) are seeded hidden.
 * Unknown category slugs are skipped and reported in $warnings.
 */
class FaqSeeder extends Seeder
{
    /**
     * @var list<string>
     */
    public array $warnings = [];

    public function run(): void
    {
        $groups = require database_path('seeders/data/faq.php');
        $categories = Category::query()->whereNull('parent_id')->get()->keyBy('slug');

        DB::transaction(function () use ($groups, $categories): void {
            foreach ($groups as $group) {
                $owner = null;

                if ($group['scope'] === 'category') {
                    $owner = $categories->get($group['slug']);

                    if ($owner === null) {
                        $this->warn("FAQ: top-level category \"{$group['slug']}\" not found, skipped");

                        continue;
                    }
                }

                $this->seedGroup($owner, $group['items']);
            }
        });
    }

    /**
     * @param  list<array{question: array<string, string>, answer: array<string, string>, confirm?: bool}>  $items
     */
    private function seedGroup(?Model $owner, array $items): void
    {
        if (FaqItem::query()->ownedBy($owner)->exists()) {
            return;
        }

        foreach (array_values($items) as $index => $data) {
            $item = new FaqItem([
                'faqable_type' => $owner?->getMorphClass(),
                'faqable_id' => $owner?->getKey(),
                'sort' => $index + 1,
                'is_active' => ! ($data['confirm'] ?? false),
            ]);
            $item->storeTranslations('question', $data['question']);
            $item->storeTranslations('answer', $data['answer']);
            $item->save();
        }
    }

    private function warn(string $message): void
    {
        $this->warnings[] = $message;
        $this->command?->warn($message);
    }
}
