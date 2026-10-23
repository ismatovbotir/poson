<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Static pages (plan 07 7a): about, contacts, delivery, warranty, B2B, legal
 * and custom pages at /{locale}/page/{slug}. Title, body (sanitized HTML) and
 * SEO texts per locale; one shared Latin slug. System pages (fixed slugs) can
 * be deactivated but never deleted. needs_review flags texts waiting for the
 * lawyer. content_updated_at changes only when the title or body change
 * (the legal "Updated" date), not on SEO, active or sort edits. The optional cover image lives in media (collection "cover"), the
 * FAQ in faq_items (faqable = page).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('template', 20)->default('default');
            $table->json('title');
            $table->json('body')->nullable();
            $table->json('meta_title')->nullable();
            $table->json('meta_description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('needs_review')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamp('content_updated_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'sort']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
