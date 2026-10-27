<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog products (plan 03 §2). Hardware, consumables, software and
 * services share this table (type).
 *
 * - Prices are integer tiyin: price = Retail (null = price on request, the
 *   only price the site shows), price_dealer / price_wholesale are admin-only
 *   and fall back to price when null (D14).
 * - Enum columns are string(20) cast to PHP enums; json columns have no
 *   defaults (D15). One shared Latin slug (D5), unique including trashed rows.
 * - is_active false = draft; soft deletes = trash (D9).
 * - search_text: lowercased sku/model/names/brand for LIKE search (D7);
 *   has_missing_specs: recomputed flag for the admin filter (D8).
 * - price_synced_at (plan 09): set by the price API; while set, the admin
 *   form shows the prices read-only and SaveProduct ignores price input;
 *   price_sync_log_id points at that batch (price_sync_logs is created
 *   just before this table for the foreign key).
 * - pricing_unit (plan 08): 'unit' or 'label'. For label products the three
 *   prices are per one label in whole soʻm, unit is roll and min_qty counts
 *   rolls; the roll types come from product_roll_type.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('sku', 64)->unique();
            $table->string('model')->nullable();
            $table->string('slug')->unique();
            $table->json('name');
            $table->json('short_description')->nullable();
            $table->json('description')->nullable();
            $table->unsignedBigInteger('price')->nullable();
            $table->unsignedBigInteger('price_dealer')->nullable();
            $table->unsignedBigInteger('price_wholesale')->nullable();
            // Plan 08: 'unit' (prices per sales unit) or 'label' (consumable labels: prices per one label, whole soʻm).
            $table->string('pricing_unit', 20)->default('unit');
            // Plan 09: last time the price API (1C) set the prices; non-null = the API owns them.
            $table->timestamp('price_synced_at')->nullable();
            // The batch that last matched the product (link on the admin form); kept null after pruning.
            $table->foreignId('price_sync_log_id')->nullable()->constrained()->nullOnDelete();
            $table->string('unit', 20);
            $table->unsignedInteger('min_qty')->default(1);
            $table->string('sale_mode', 20);
            $table->string('stock_status', 20);
            $table->unsignedSmallInteger('warranty_months')->nullable();
            $table->string('badge', 20)->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->boolean('has_missing_specs')->default(false);
            $table->text('search_text')->nullable();
            $table->json('meta_title')->nullable();
            $table->json('meta_description')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category_id', 'is_active', 'sort']);
            $table->index('brand_id');
            $table->index(['is_active', 'is_featured']);
            $table->index(['is_active', 'price']);
            $table->index('type');
            $table->index('has_missing_specs');
            $table->index('price_synced_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
