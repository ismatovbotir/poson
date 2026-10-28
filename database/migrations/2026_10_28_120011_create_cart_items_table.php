<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lines of a cart (plan 11 v2 §2). line_key is "p{product}" or
 * "p{product}-r{rollType}" (App\Support\Cart\CartLineKey) and is never
 * null, so unique(cart_id, line_key) also holds for lines without a roll
 * type (a nullable roll_type_id alone would allow duplicates). qty is
 * pieces, or rolls for a label product. No prices are stored.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->string('line_key', 32);
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('roll_type_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('qty');
            $table->timestamps();

            $table->unique(['cart_id', 'line_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
