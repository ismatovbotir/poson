<?php

namespace App\Actions\Faq;

use App\Models\FaqItem;

/**
 * Delete an FAQ item (the remaining items keep their order).
 */
class DeleteFaqItem
{
    public function handle(FaqItem $item): void
    {
        $item->delete();
    }
}
