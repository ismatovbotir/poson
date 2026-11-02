<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * YouTube videos (plan 19): an ordered list of videos per product, category,
 * brand or news post (polymorphic owner, like faq_items). Only the 11-character
 * YouTube id is stored; titles come from oEmbed (queued) and/or are typed by
 * staff. youtube_id is ascii_bin on MySQL so ids stay case-sensitive.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->morphs('videoable');

            $youtubeId = $table->char('youtube_id', 11);

            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $youtubeId->charset('ascii')->collation('ascii_bin');
            }

            $table->json('title')->nullable();
            $table->string('source_title', 200)->nullable();
            $table->string('source_author', 100)->nullable();
            $table->boolean('source_title_safe')->default(true);
            $table->date('published_on')->nullable();
            $table->string('fetch_status', 20)->default('pending');
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('thumbnail_at')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['videoable_type', 'videoable_id', 'youtube_id']);
            $table->index(['videoable_type', 'videoable_id', 'is_active', 'sort']);
            $table->index('youtube_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
