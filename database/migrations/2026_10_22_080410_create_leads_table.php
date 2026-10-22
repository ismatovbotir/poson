<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leads / Murojaatlar (plan 05 §Schema). Reference "L-{id}".
 *
 * source = channel (LeadSource), type = what was asked (LeadType), status =
 * LeadStatus (lost_reason + lost_note when lost). The phone is normalized
 * (+998XXXXXXXXX) and indexed for the duplicate check and the customer match.
 * duplicate_of_id flags an earlier lead with the same phone (never merged).
 * estimated_amount is tiyin. Attribution (page, first-touch channel/source,
 * landing, utm) comes from the site form. Every user / customer / lead link
 * is unset on delete.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20);
            $table->string('type', 20);
            $table->string('status', 20)->default('new');
            $table->string('lost_reason', 30)->nullable();
            $table->string('lost_note', 500)->nullable();
            $table->string('name', 120);
            $table->string('phone', 16)->index();
            $table->string('email')->nullable();
            $table->string('company', 160)->nullable();
            $table->string('inn', 9)->nullable();
            $table->text('message')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('duplicate_of_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->unsignedBigInteger('estimated_amount')->nullable();
            $table->string('locale', 5)->default('uz');
            $table->string('page_url', 2048)->nullable();
            $table->string('traffic_channel', 20)->nullable();
            $table->string('traffic_source')->nullable();
            $table->string('landing_path', 2048)->nullable();
            $table->json('utm')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['manager_id', 'status']);
            $table->index(['type', 'status']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
