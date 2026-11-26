<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 44 §2-3: a participant of a tracked group. role is set by staff (bots
 * are always "bot"); linked staff user, customer and telegram user are
 * optional and null on delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_group_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('telegram_group_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('telegram_id');
            $table->string('role', 10)->default('unknown');
            $table->boolean('is_bot')->default(false);
            $table->string('first_name', 64)->nullable();
            $table->string('username', 64)->nullable();
            // The member's own shared contact only (contact.user_id = sender), normalised, plaintext; never from text (OG-6).
            $table->string('phone', 24)->nullable();
            $table->string('member_status', 12)->nullable();
            $table->foreignId('telegram_user_id')->nullable()->constrained('telegram_users')->nullOnDelete();
            $table->foreignId('staff_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->foreignId('role_set_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('role_set_at')->nullable();
            $table->timestamps();

            $table->unique(['telegram_group_id', 'telegram_id']);
            $table->index(['telegram_group_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_group_members');
    }
};
