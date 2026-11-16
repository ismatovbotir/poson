<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 33 §3: the website knowledge index of the chat bot AI. One row is one
 * chunk (plain text, at most 1,200 characters) of one public source (page,
 * news, article, brand, service or software product) in one locale. Only
 * publicly visible content is ever written (K-1); money amounts are masked
 * and brand-unsafe or instruction-like chunks are never stored (K-3, K-4).
 * No foreign key (polymorphic source): rows are removed by the indexer.
 * "url" is for display only; links are rebuilt from (type, id, locale) when
 * a reply is built (K-5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_knowledge_chunks', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 12);
            $table->unsignedBigInteger('source_id');
            $table->char('locale', 2);
            $table->unsignedTinyInteger('chunk');
            $table->string('title', 200);
            $table->string('url', 255);
            $table->text('text');
            $table->text('search_text');
            $table->unsignedTinyInteger('priority');
            $table->timestamp('visible_from')->nullable();
            $table->char('content_hash', 64);
            $table->timestamp('indexed_at')->useCurrent();

            $table->unique(['source_type', 'source_id', 'locale', 'chunk'], 'ai_knowledge_chunks_source_unique');
            $table->index(['locale', 'visible_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_knowledge_chunks');
    }
};
