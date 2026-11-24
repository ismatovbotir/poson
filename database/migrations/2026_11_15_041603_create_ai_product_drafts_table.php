<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 32 §3, §14 (PW-18, plan changes 1, 2, 4, 5): one AI product wizard
 * run (create or improve) of one user. Holds the poll status, the server-side
 * image refs and flags (input), the confirmed facts, the validated proposals
 * (result), per-stage models and tokens, the field snapshot hashes for the
 * apply conflict check, the apply progress and the expiry. Never a raw
 * answer, prompt, image bytes or base64; file paths only as refs inside
 * ai-drafts/{uuid}. Rows are deleted after chatbot.ai.product.keep_days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_product_drafts', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('mode', 8);
            $table->foreignId('product_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->string('status', 12)->index();
            $table->json('input');
            $table->json('facts')->nullable();
            $table->json('result')->nullable();
            $table->json('snapshot')->nullable();
            $table->json('apply_state')->nullable();
            $table->string('error', 40)->nullable();
            $table->string('vision_model', 40)->nullable();
            $table->string('write_model', 40)->nullable();
            $table->unsignedInteger('vision_input_tokens')->default(0);
            $table->unsignedInteger('vision_output_tokens')->default(0);
            $table->unsignedInteger('write_input_tokens')->default(0);
            $table->unsignedInteger('write_output_tokens')->default(0);
            $table->unsignedInteger('vision_ms')->nullable();
            $table->unsignedInteger('write_ms')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('expires_at')->useCurrent()->index();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_product_drafts');
    }
};
