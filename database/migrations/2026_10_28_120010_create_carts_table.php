<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public site carts (plan 11 v2 §2). A cart is identified only by the opaque
 * ULID in the encrypted "poson_cart" cookie (App\Support\Cart\CartToken) and
 * holds no personal data and no prices (CartPricer re-reads them). The row
 * is created lazily on the first "Add to cart" and pruned by carts:prune.
 *
 * converted_order_id: the order placed from this cart (plan 11b). The orders
 * table doesn't exist yet in 11a, so the column has no foreign key here.
 * This migration is timestamped after the slot reserved for 11b's
 * create_orders_table (2026_10_28_1200xx), so 11b edits this file to
 * ->constrained('orders')->nullOnDelete() (pre-production rule) and runs
 * migrate:fresh.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->ulid('token')->unique();
            $table->string('locale', 5);
            // TODO(11b): ->constrained('orders')->nullOnDelete() once create_orders_table runs before this migration.
            $table->unsignedBigInteger('converted_order_id')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->index('updated_at');
            $table->index('converted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
