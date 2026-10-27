<?php

namespace App\Livewire\Admin\Articles;

use App\Enums\NewsKind;
use App\Livewire\Admin\News\Form as NewsForm;
use Livewire\Attributes\Layout;

/**
 * Create / edit a knowledge article (decision 2026-10-24): the news post form
 * for kind = article (all the same fields, news.* permissions). New posts are
 * saved as articles; a news post opened here is a 404.
 */
#[Layout('layouts::admin')]
class Form extends NewsForm
{
    protected function kind(): NewsKind
    {
        return NewsKind::Article;
    }
}
