<?php

namespace App\Livewire\Admin\Articles;

use App\Enums\NewsKind;
use App\Livewire\Admin\News\Index as NewsIndex;
use Livewire\Attributes\Layout;

/**
 * Knowledge articles list (decision 2026-10-24) at /admin/articles: the news
 * list for kind = article, with the same filters, sort and news.* permissions.
 */
#[Layout('layouts::admin')]
class Index extends NewsIndex
{
    protected function kind(): NewsKind
    {
        return NewsKind::Article;
    }
}
