<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Support\Site\NewsFeed;
use Illuminate\Http\Response;

/**
 * GET /{locale}/news/rss.xml (plan 07 7b): RSS 2.0 of the news visible in
 * the locale (App\Support\Site\NewsFeed). An empty channel while there is none.
 */
class NewsFeedController extends Controller
{
    public function __invoke(NewsFeed $feed): Response
    {
        return response($feed->xml(app()->getLocale()), 200, [
            'Content-Type' => 'application/rss+xml; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
