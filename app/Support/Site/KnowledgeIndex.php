<?php

namespace App\Support\Site;

use App\Enums\AiKnowledgeSource;
use App\Enums\NewsKind;
use App\Enums\ProductType;
use App\Jobs\IndexAiKnowledge;
use App\Models\Brand;
use App\Models\Category;
use App\Models\FaqItem;
use App\Models\NewsPost;
use App\Models\Page;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The model hook of the website knowledge index (plan 33 §4, K-7), called
 * from booted() of Page, NewsPost, Brand, Product and FaqItem like IndexNow::record():
 * everything in try/catch (a failed hook never breaks a save) and the job is
 * queued after commit, one unique job per source (a hundred saves in one
 * transaction make one job). It queues only when the record is created,
 * deleted (Product also restored) or one of its AI_KNOWLEDGE_FIELDS changed,
 * so a price, stock or sort change queues nothing. The job carries only the
 * source type and id.
 */
final class KnowledgeIndex
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const DELETED = 'deleted';

    public const RESTORED = 'restored';

    /**
     * @param  self::CREATED|self::UPDATED|self::DELETED|self::RESTORED  $event
     */
    public static function queue(Model $model, string $event): void
    {
        try {
            if (! config('chatbot.ai.knowledge.hooks', true)) {
                return;
            }

            $targets = self::targets($model, $event);

            if ($targets === []) {
                return;
            }

            DB::afterCommit(function () use ($targets): void {
                foreach ($targets as [$type, $id]) {
                    try {
                        IndexAiKnowledge::dispatch($type->value, $id);
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The (source type, id) pairs to re-index: the current one and, when a
     * news kind or product type changed, the old one (its chunks must go).
     *
     * @return list<array{0: AiKnowledgeSource, 1: int}>
     */
    private static function targets(Model $model, string $event): array
    {
        if ($event === self::UPDATED && ! $model->wasChanged(self::fieldsOf($model))) {
            return [];
        }

        if ($event === self::RESTORED && ! $model instanceof Product) {
            return [];
        }

        $id = (int) $model->getKey();
        $types = match (true) {
            $model instanceof Page => [AiKnowledgeSource::Page],
            $model instanceof Brand => [AiKnowledgeSource::Brand],
            $model instanceof FaqItem => self::faqTypes($model),
            $model instanceof NewsPost => self::newsTypes($model),
            $model instanceof Product => self::productTypes($model),
            default => [],
        };

        return array_map(fn (AiKnowledgeSource $type): array => [$type, $id], $types);
    }

    /**
     * @return list<string>
     */
    private static function fieldsOf(Model $model): array
    {
        return $model::AI_KNOWLEDGE_FIELDS;
    }

    /**
     * @return list<AiKnowledgeSource>
     */
    private static function newsTypes(NewsPost $post): array
    {
        $kinds = [$post->kind];

        if ($post->wasChanged('kind') && NewsKind::tryFrom((string) $post->getRawOriginal('kind')) !== null) {
            $kinds[] = NewsKind::from((string) $post->getRawOriginal('kind'));
        }

        return array_values(array_unique(array_map(
            fn (NewsKind $kind): AiKnowledgeSource => $kind === NewsKind::Article ? AiKnowledgeSource::Article : AiKnowledgeSource::News,
            $kinds,
        ), SORT_REGULAR));
    }

    /**
     * Only category and page items are knowledge (the general FAQ is in the
     * static block, product FAQ never): a general or product item queues
     * nothing unless it just moved away from another owner (its chunks must go).
     *
     * @return list<AiKnowledgeSource>
     */
    private static function faqTypes(FaqItem $item): array
    {
        $owners = [(new Category)->getMorphClass(), (new Page)->getMorphClass()];

        if (in_array($item->faqable_type, $owners, true) || $item->wasChanged(['faqable_type', 'faqable_id'])) {
            return [AiKnowledgeSource::Faq];
        }

        return [];
    }

    /**
     * Only services and software are knowledge (hardware and consumables never).
     *
     * @return list<AiKnowledgeSource>
     */
    private static function productTypes(Product $product): array
    {
        $types = [$product->type];

        if ($product->wasChanged('type') && ProductType::tryFrom((string) $product->getRawOriginal('type')) !== null) {
            $types[] = ProductType::from((string) $product->getRawOriginal('type'));
        }

        $sources = [];

        foreach ($types as $type) {
            $source = match ($type) {
                ProductType::Service => AiKnowledgeSource::Service,
                ProductType::Software => AiKnowledgeSource::Software,
                default => null,
            };

            if ($source !== null && ! in_array($source, $sources, true)) {
                $sources[] = $source;
            }
        }

        return $sources;
    }
}
