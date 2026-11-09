<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 28 C1 / CB-10: phrases the bot did not understand, for the owner to
 * teach it. "normalized" and "sample" are stored already redacted (phone and
 * long digit runs, passports, emails, links masked) and never carry a chat id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_unmatched', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 10);
            $table->char('locale', 2);
            $table->string('normalized', 120);
            $table->string('sample', 200);
            $table->unsignedInteger('count')->default(1);
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_rule_id')->nullable()->constrained('chat_rules')->nullOnDelete();
            $table->timestamps();

            $table->unique(['channel', 'locale', 'normalized']);
            $table->index(['resolved_at', 'count']);
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_unmatched');
    }
};
