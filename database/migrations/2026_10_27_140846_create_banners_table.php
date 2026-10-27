<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Banners (plan 07 7c): the home slider below the static hero (placement
 * home_hero) and one banner on page 1 of a category listing (placement
 * category, category_id required). Title, text and button label per locale;
 * the link is an internal path ("/uz/catalog/...") or an https URL. Shown
 * while is_active and within starts_at / ends_at (both optional, UTC, a
 * DATETIME: no 2038 limit), ordered by sort within the placement. Desktop
 * (required) and mobile images live in media (collections "desktop" and
 * "mobile"). A deleted category deletes its banners.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('placement', 20);
            $table->foreignId('category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('title');
            $table->json('text')->nullable();
            $table->json('button_label')->nullable();
            $table->string('link', 2048)->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['placement', 'is_active', 'sort']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
