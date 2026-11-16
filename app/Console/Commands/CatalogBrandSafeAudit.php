<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Support\Catalog\ProductTextRules;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Read-only audit of the stored product texts against the brand rule.
 */
#[Signature('catalog:brand-safe-audit')]
#[Description('List stored product texts that break the low-price / discount wording rule (read-only)')]
class CatalogBrandSafeAudit extends Command
{
    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Checks the name, short description, description, meta texts, model and text specs of every product (trashed too) with the same rules SaveProduct enforces (ProductTextRules). Prints one line per finding: product id, field and locale. It never prints the text and never changes data.
Run it once on production before deploying the rule, then fix the listed products in the admin (they cannot be saved again until the text is clean).
Not scheduled.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $findings = 0;
        $products = 0;

        Product::query()->withTrashed()->orderBy('id')->chunkById(200, function ($chunk) use (&$findings, &$products): void {
            foreach ($chunk as $product) {
                $products++;

                foreach (ProductTextRules::TRANSLATABLE as $field) {
                    foreach ((array) $product->getTranslations($field) as $locale => $value) {
                        $bad = is_string($value) && $value !== ''
                            && ($field === 'description' ? ProductTextRules::violatesDescription($value) : ProductTextRules::violatesPlain($value));

                        $findings += $this->report($bad, $product->id, $field, (string) $locale);
                    }
                }

                $findings += $this->report(is_string($product->model) && ProductTextRules::violatesPlain($product->model), $product->id, 'model', '-');
            }
        });

        ProductAttributeValue::query()->whereNotNull('value_text')->orderBy('id')->chunkById(500, function ($chunk) use (&$findings): void {
            foreach ($chunk as $value) {
                foreach ((array) $value->getTranslations('value_text') as $locale => $text) {
                    $findings += $this->report(is_string($text) && ProductTextRules::violatesPlain($text), $value->product_id, 'spec', (string) $locale);
                }
            }
        });

        $this->line("Checked {$products} products, {$findings} findings.");

        return $findings === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function report(bool $bad, int $productId, string $field, string $locale): int
    {
        if (! $bad) {
            return 0;
        }

        $this->line("product {$productId} {$field} {$locale}");

        return 1;
    }
}
