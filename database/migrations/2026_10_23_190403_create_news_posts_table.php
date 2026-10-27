<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * News (plan 07 7b) at /{locale}/news/{slug}, and knowledge articles at
 * /{locale}/articles/{slug} (kind = article, decision 2026-10-24; one shared
 * slug space). Title, excerpt, body (sanitized
 * HTML) and SEO texts per locale; one shared Latin slug. A post is public when
 * status = published and published_at <= now (a future date = scheduled, no
 * cron; stored in UTC as a DATETIME, which has no 2038 limit). "locales" lists the locales with both title and body filled in
 * (kept by the model on save): the post exists only in those locales.
 * content_updated_at changes only when the title, excerpt or body change. The
 * cover image lives in media (collection "cover"); related products and
 * categories in news_post_product and category_news_post.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('news_posts', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('kind', 20)->default('news');
            $table->string('status', 20)->default('draft');
            $table->dateTime('published_at')->nullable();
            $table->json('title');
            $table->json('excerpt')->nullable();
            $table->json('body')->nullable();
            $table->json('meta_title')->nullable();
            $table->json('meta_description')->nullable();
            $table->json('locales')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('content_updated_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'published_at']);
            $table->index(['kind', 'status', 'published_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('news_posts');
    }
};
