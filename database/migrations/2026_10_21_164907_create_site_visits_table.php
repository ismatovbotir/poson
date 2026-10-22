<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw human traffic (plan 04 4c): one row per page view or lead, written by
 * the TrackVisit middleware / RecordConversion, kept 30 days and rolled up
 * into traffic_daily(_pages). No foreign keys (product/category ids are only
 * hints, rows outlive deletions) and no IP: visitor_hash is a daily-salted
 * hash, the salt lives in the cache only.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('site_visits', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at');
            $table->string('type', 10);
            $table->char('visitor_hash', 16);
            $table->boolean('is_entry')->default(false);
            $table->string('channel', 10);
            $table->string('source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 150)->nullable();
            $table->string('referrer_host', 150)->nullable();
            $table->string('route_name', 100)->nullable();
            $table->string('path', 255);
            $table->string('locale', 5)->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('device', 10);
            $table->unsignedSmallInteger('status')->default(200);

            // Daily roll-up and pruning scan by time.
            $table->index('occurred_at');
            $table->index(['type', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_visits');
    }
};
