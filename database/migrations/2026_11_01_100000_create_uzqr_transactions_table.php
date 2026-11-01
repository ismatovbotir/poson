<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dynamic UzQR invoices (plan 11e §3, security conditions 35–52): one row
 * per invoice/create attempt of an order.
 *
 * - external_id: ours, "PS" (prod) / "PT" (test) + the 12-digit row id, set
 *   right after insert; never reused after a final status;
 * - invoice_id / pay_id: the bank's, unique per mode;
 * - amount: tiyin, immutable (status calls carry no amount);
 * - qr_text: the bank's QR payload (validated printable ASCII ≤ 2000);
 * - card_type / card_last4: the only payer data kept (never payer_phone);
 * - last_error_code: the bank's numeric error code; last_error: our own code;
 * - resolution*: Admin decision on an unmatched payment (condition 50);
 * - receipt_confirmed_*: finance ticked the fiscal receipt off (condition 47).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('uzqr_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 10);
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('external_id', 20)->nullable()->unique();
            $table->string('invoice_id', 25)->nullable();
            $table->string('pay_id', 20)->nullable();
            $table->unsignedBigInteger('amount');
            $table->string('status', 20);
            $table->unsignedTinyInteger('remote_status')->nullable();
            $table->text('qr_text')->nullable();
            $table->unsignedTinyInteger('card_type')->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->string('settlement', 20)->nullable();
            $table->string('unmatched_reason', 40)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->unsignedInteger('check_failures')->default(0);
            $table->unsignedSmallInteger('last_error_code')->nullable();
            $table->string('last_error', 40)->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('alerted_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('resolution', 30)->nullable();
            $table->string('resolution_reference', 100)->nullable();
            $table->string('resolution_note', 500)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('receipt_confirmed_at')->nullable();
            $table->foreignId('receipt_confirmed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['mode', 'invoice_id']);
            $table->unique(['mode', 'pay_id']);
            $table->index(['status', 'next_check_at']);
            $table->index(['order_id', 'status']);
            $table->index(['mode', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('uzqr_transactions');
    }
};
