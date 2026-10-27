<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quote lines (plan 06). name / sku / unit are snapshots (a free-text line,
 * e.g. a service, has no product); price is per unit in tiyin including VAT,
 * prefilled from the customer's price level and editable; total = price × qty.
 * A label line (plan 08) also snapshots its roll: roll_type_id,
 * labels_per_roll, core_diameter_mm, label_size ("100×150") and
 * price_per_label (tiyin); its qty is rolls and its price is per roll.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('unit', 20)->nullable();
            $table->unsignedInteger('qty')->default(1);
            $table->unsignedBigInteger('price')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            // Plan 08 roll snapshot of a label line (all null otherwise): qty = rolls,
            // price = per roll = price_per_label × labels_per_roll; price_per_label is the source of truth.
            $table->foreignId('roll_type_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('labels_per_roll')->nullable();
            $table->decimal('core_diameter_mm', 4, 1)->nullable();
            $table->string('label_size', 20)->nullable();
            $table->unsignedBigInteger('price_per_label')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quote_items');
    }
};
