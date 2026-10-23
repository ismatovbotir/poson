<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quotes / tijorat takliflari (plan 06). number "Q-YYYY-NNNN" (sequences
 * table); ulid = the unguessable public link /q/{ulid}. A customer with
 * quotes can't be deleted (restrictOnDelete); lead and people links are
 * unset on delete.
 *
 * Money in tiyin. Prices include VAT (QQS 12%): total is what the customer
 * pays, vat_amount = round(total × 12 / 112) is shown as "including VAT".
 * subtotal = sum of the item totals (equal to total: no discounts, brand
 * rule, so there are no discount columns). price_level and locale are
 * snapshots from the customer.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->ulid('ulid')->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->string('price_level', 20)->default('retail');
            $table->string('locale', 5)->default('uz');
            $table->date('valid_until');
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('vat_amount')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['manager_id', 'status']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
