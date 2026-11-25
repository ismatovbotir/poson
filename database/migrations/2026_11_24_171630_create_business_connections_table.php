<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 41 §2: Telegram Business connections of the customer bot. One row per
 * connection id; is_allowed is true only while the owner is on the
 * office.owner_telegram_ids allow-list (VO-1). rights holds whitelisted
 * BusinessBotRights booleans only (VO-13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('connection_id', 128)->unique();
            $table->unsignedBigInteger('owner_telegram_id');
            $table->bigInteger('user_chat_id')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->boolean('is_allowed')->default(false);
            $table->boolean('can_reply')->default(false);
            $table->json('rights')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            $table->index('owner_telegram_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_connections');
    }
};
