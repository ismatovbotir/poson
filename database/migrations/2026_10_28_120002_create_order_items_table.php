<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order lines (plan 11 v2 §2): snapshots of name (order locale), SKU, unit,
 * price per unit (tiyin, VAT included), qty and total = price × qty, the
 * fiscal codes (IKPU, package code, VAT 12%) and whether the product was on
 * order. A label line (plan 08) snapshots its roll like quote_items: qty =
 * rolls, price = per roll.
 *
 * reference_price / price_edited_by_id: a manual order line whose price was
 * edited by staff (security condition 26): the level price it was edited
 * from, so edited and below-reference lines are flagged.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('unit', 20)->nullable();
            $table->unsignedInteger('qty')->default(1);
            $table->unsignedBigInteger('price')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('reference_price')->nullable();
            $table->boolean('price_edited')->default(false);
            $table->string('ikpu_code', 17)->nullable();
            $table->string('package_code', 20)->nullable();
            $table->unsignedTinyInteger('vat_percent')->default(12);
            $table->boolean('was_on_order')->default(false);
            $table->foreignId('roll_type_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('labels_per_roll')->nullable();
            $table->decimal('core_diameter_mm', 4, 1)->nullable();
            $table->string('label_size', 20)->nullable();
            $table->unsignedBigInteger('price_per_label')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['order_id', 'sort']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
