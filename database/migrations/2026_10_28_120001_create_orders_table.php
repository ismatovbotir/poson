<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orders (plan 11 v2 §2). number "O-YYYY-NNNN" (real) or "T-YYYY-NNNN"
 * (TEST orders, Admin only); ulid = the customer's unguessable /o/{ulid}
 * link, valid until public_until (90 days, renewable: a new ulid).
 *
 * Money in integer tiyin, prices include VAT 12%: total = subtotal +
 * delivery_amount, vat_amount = its VAT part (App\Support\Vat). A paid
 * order's total never changes; delivery billed later is an order_payments
 * row (purpose delivery). delivery_pricing: fixed (delivery_amount in the
 * total, possibly 0) or separate (confirmed and billed by the manager).
 *
 * Buyer data are snapshots typed at checkout (never shown on /o): name,
 * phone (+998…), company name and tin (company STIR or YaTT STIR / PINFL).
 * customer_id is set only when tin AND phone match one customer
 * (restrictOnDelete: DeleteCustomer blocks while orders exist).
 * attribution / traffic_channel: sanitized first-touch analytics, no PII.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->ulid('ulid')->unique();
            $table->timestamp('public_until')->nullable();
            $table->boolean('is_test')->default(false);
            $table->string('source', 20);
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 20)->default('new');
            $table->string('payment_status', 20)->default('unpaid');
            $table->string('payment_method', 20);
            $table->timestamp('payment_pending_at')->nullable();
            $table->string('price_level', 20)->default('retail');
            $table->string('locale', 5)->default('uz');

            $table->string('buyer_type', 20);
            $table->string('name', 120);
            $table->string('phone', 16);
            $table->string('company_name', 160)->nullable();
            $table->string('tin', 14)->nullable();

            $table->string('delivery_type', 20);
            $table->string('region', 30)->nullable();
            $table->string('address', 500)->nullable();
            $table->boolean('kokand_address_confirmed')->default(false);
            $table->text('comment')->nullable();
            $table->text('staff_note')->nullable();

            $table->string('delivery_pricing', 20)->default('fixed');
            $table->unsignedBigInteger('delivery_amount')->default(0);
            $table->string('delivery_ikpu', 17)->nullable();
            $table->string('delivery_package_code', 20)->nullable();

            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('vat_amount')->default(0);
            $table->unsignedBigInteger('total')->default(0);

            $table->date('invoice_valid_until')->nullable();
            $table->unsignedSmallInteger('invoice_version')->default(1);
            $table->timestamp('amount_updated_at')->nullable();

            $table->json('attribution')->nullable();
            $table->string('traffic_channel', 20)->nullable();

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();

            $table->index(['is_test', 'status', 'created_at']);
            $table->index(['is_test', 'payment_status', 'created_at']);
            $table->index(['payment_method', 'payment_status']);
            $table->index('customer_id');
            $table->index('phone');
            $table->index('tin');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
