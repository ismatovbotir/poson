<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Label roll types (plan 08): labels per roll + core diameter. The label size
 * (W×H) stays on the product (label_width / label_height attributes). The
 * display name comes from a lang key (RollType::label()). is_active false
 * hides the roll type everywhere (pickers, site, new quote lines).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('roll_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('labels_per_roll');
            $table->decimal('core_diameter_mm', 4, 1);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['labels_per_roll', 'core_diameter_mm']);
            $table->index(['is_active', 'sort']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roll_types');
    }
};
