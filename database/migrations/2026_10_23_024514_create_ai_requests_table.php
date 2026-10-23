<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin AI assist request log (plan 02 §4): one row per request, used for
 * the per-user daily quota, the monthly budget and the usage screen.
 *
 * prompt = the final hint only (never the system prompt, context or keys);
 * response = {uz, ru, en} texts; both are cleared after 90 days
 * (ai:prune-requests). brand_warnings = BrandGuard matches per locale.
 * cost_micro_usd: 1 USD = 1 000 000. subject = the saved record the text
 * was written for (category / product), null for unsaved forms.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('feature', 30);
            $table->string('provider', 20);
            $table->string('model', 80)->nullable();
            $table->string('field_key', 60)->nullable();
            $table->nullableMorphs('subject');
            $table->string('source_locale', 5)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('error_code', 30)->nullable();
            $table->text('prompt')->nullable();
            $table->json('response')->nullable();
            $table->json('brand_warnings')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedSmallInteger('images')->default(0);
            $table->unsignedBigInteger('cost_micro_usd')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('pruned_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
            $table->index(['provider', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
