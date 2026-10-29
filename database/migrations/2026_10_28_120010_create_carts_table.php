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
 * converted_order_id: the order placed from this cart (plan 11b); the
 * orders migration (2026_10_28_120001) runs before this one. Deleting an
 * order (only TEST orders can be) unsets it.
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
            $table->foreignId('converted_order_id')->nullable()->constrained('orders')->nullOnDelete();
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
