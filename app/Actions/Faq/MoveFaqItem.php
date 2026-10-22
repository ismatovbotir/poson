<?php

namespace App\Actions\Faq;

use App\Models\FaqItem;
use Illuminate\Support\Facades\DB;

/**
 * Move an FAQ item one place up (-1) or down (+1) within its owner's list;
 * the list is renumbered 1..n in the same transaction.
 */
class MoveFaqItem
{
    public function handle(FaqItem $item, int $direction): void
    {
        DB::transaction(function () use ($item, $direction): void {
            $query = FaqItem::query()->lockForUpdate()->ordered();

            $item->isGeneral()
                ? $query->whereNull('faqable_type')->whereNull('faqable_id')
                : $query->where('faqable_type', $item->faqable_type)->where('faqable_id', $item->faqable_id);

            $ids = $query->pluck('id')->all();
            $from = array_search($item->id, $ids, true);
            $to = $from === false ? false : $from + ($direction < 0 ? -1 : 1);

            if ($from === false || $to < 0 || $to >= count($ids)) {
                return;
            }

            [$ids[$from], $ids[$to]] = [$ids[$to], $ids[$from]];

            foreach ($ids as $index => $id) {
                FaqItem::query()->whereKey($id)->update(['sort' => $index + 1]);
            }
        });
    }
}
