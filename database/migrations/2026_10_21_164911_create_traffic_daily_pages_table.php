<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Top pages per day (plan 04 4c), rebuilt per date by AggregateTraffic and
 * kept 25 months: the 500 most requested path + status pairs per date and
 * kind (visits), per date and kind (partner: "Try" redirects, one row per
 * /{locale}/go/{slug} path), or per date, kind (bots) and bot key. No foreign keys:
 * product_id / category_id are kept for reports even after a deletion.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('traffic_daily_pages', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('kind', 10);
            $table->string('bot', 40)->default('');
            $table->string('path', 255);
            $table->unsignedSmallInteger('status')->default(200);
            $table->string('route_name', 100)->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedInteger('hits')->default(0);
            $table->unsignedInteger('visitors')->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->unique(['date', 'kind', 'bot', 'path', 'status'], 'traffic_daily_pages_dimensions_unique');
            // Dashboards: one kind over a date range, optionally one bot.
            $table->index(['kind', 'date', 'bot']);
            $table->index('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('traffic_daily_pages');
    }
};
