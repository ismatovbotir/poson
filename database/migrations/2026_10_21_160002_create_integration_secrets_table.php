<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integration secrets edited in Settings → Integrations (plan 04 S2): API
 * keys, bot token, payment keys. "value" holds the ciphertext of the
 * encrypted cast (APP_KEY); "hint" the last 4 characters of values of 20+
 * characters, for the masked "••••1234" display. Non-secret integration
 * values live in "settings" (group integrations).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('integration_secrets', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value');
            $table->char('hint', 4)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integration_secrets');
    }
};
