<?php

namespace App\Livewire\Site\Articles;

use App\Enums\NewsKind;
use App\Livewire\Site\News\Show as NewsShow;

/**
 * Knowledge article (decision 2026-10-24) at /{locale}/articles/{slug}: the
 * news post page for kind = article (same visibility, locale, hreflang and
 * canonical rules), with Article JSON-LD instead of NewsArticle and no RSS
 * link. A news post opened here is a 404.
 */
class Show extends NewsShow
{
    protected function kind(): NewsKind
    {
        return NewsKind::Article;
    }
}
