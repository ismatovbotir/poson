<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily traffic roll-up (plan 04 4c), rebuilt per date by AggregateTraffic
 * and kept 25 months. One row per date + kind + dimension (+ channel) + value:
 *
 *   kind visits: dimension total (value ''), channel (value = channel),
 *                source (channel + value = source), device, locale
 *   kind bots:   dimension bot (value = bot key)
 *
 * visitors are distinct daily visitor hashes (humans) or distinct IPs (bots)
 * within the row, so they are exact per row and per day.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('traffic_daily', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('kind', 10);
            $table->string('dimension', 20);
            $table->string('channel', 10)->default('');
            $table->string('value', 100)->default('');
            $table->unsignedInteger('visitors')->default(0);
            $table->unsignedInteger('page_views')->default(0);
            $table->unsignedInteger('entries')->default(0);
            $table->unsignedInteger('leads')->default(0);
            $table->unsignedInteger('hits')->default(0);
            $table->unsignedInteger('verified_hits')->default(0);
            $table->unsignedInteger('spoofed_hits')->default(0);
            $table->unsignedInteger('errors_4xx')->default(0);
            $table->unsignedInteger('errors_5xx')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['date', 'kind', 'dimension', 'channel', 'value'], 'traffic_daily_dimensions_unique');
            $table->index(['kind', 'dimension', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('traffic_daily');
    }
};
