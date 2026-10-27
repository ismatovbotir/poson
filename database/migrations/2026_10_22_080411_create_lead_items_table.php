<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Products a lead asks about (plan 05 §Schema). sku and product_name are
 * snapshots taken when the item is saved, so the item still reads right
 * after the product changes or is deleted (product_id is then unset).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lead_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku')->nullable();
            $table->string('product_name');
            $table->unsignedInteger('qty')->default(1);
            // Plan 08: the roll a label product was asked in (qty = rolls); snapshots, null otherwise.
            $table->foreignId('roll_type_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('labels_per_roll')->nullable();
            $table->decimal('core_diameter_mm', 4, 1)->nullable();
            $table->string('note', 500)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_items');
    }
};
