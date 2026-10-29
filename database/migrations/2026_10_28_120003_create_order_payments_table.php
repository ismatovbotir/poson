<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order payment ledger (plan 11 v2 §2, security conditions 20–23): one row
 * per payment of the goods total or of a delivery charge billed separately.
 * Rows are never deleted (only TEST orders' rows, with the order): a voided
 * manual payment or a cancelled delivery charge becomes "cancelled".
 *
 * - amount: tiyin, exactly the outstanding amount (no partial payments);
 * - reference: the bank / payment reference typed by staff (≤100);
 *   reference_key = "method:normalized reference" while the row is active,
 *   unique, so one reference can't confirm two payments of a method
 *   (null again once the row is cancelled);
 * - provider / provider_ref: Payme / Click transaction (plans 11c/11d);
 * - reconciled_at / reconciled_by_id: Admin ticked it off against the bank
 *   statement (condition 21).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('purpose', 20);
            $table->string('method', 20)->nullable();
            $table->string('receipt_channel', 20)->nullable();
            $table->string('status', 20);
            $table->unsignedBigInteger('amount');
            $table->boolean('is_test')->default(false);
            $table->string('provider', 20)->nullable();
            $table->string('provider_ref', 64)->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('reference_key', 140)->nullable()->unique();
            $table->text('note')->nullable();
            $table->date('paid_on')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->foreignId('refunded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('refund_reason', 500)->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->foreignId('reconciled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['provider', 'provider_ref']);
            $table->index(['order_id', 'purpose']);
            $table->index(['status', 'paid_at']);
            $table->index(['is_test', 'paid_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_payments');
    }
};
