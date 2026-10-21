<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec values of a product. One row per attribute; a multiselect value is
 * one row per chosen option. Exactly one of the value columns is used,
 * depending on the attribute type. is_key marks specs shown on the product
 * card (at most 4 attributes per product, enforced by SaveProduct).
 *
 * Attributes and options can't be deleted while values use them (restrict).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained()->restrictOnDelete();
            $table->foreignId('attribute_option_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('value_text')->nullable();
            $table->decimal('value_number', 14, 4)->nullable();
            $table->boolean('value_bool')->nullable();
            $table->boolean('is_key')->default(false);
            $table->unsignedInteger('sort')->default(0);

            $table->index(['product_id', 'attribute_id']);
            $table->index(['attribute_id', 'attribute_option_id']);
            $table->index(['attribute_id', 'value_number']);
            $table->index(['attribute_id', 'value_bool']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_attribute_values');
    }
};
