<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roll types a label product is sold in (plan 08). Attached = available;
 * sort orders the site picker (the first one is preselected). A roll type
 * can't be deleted while attached (restrictOnDelete; DeleteRollType says so
 * first).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_roll_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('roll_type_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);

            $table->unique(['product_id', 'roll_type_id']);
            $table->index('roll_type_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_roll_type');
    }
};
