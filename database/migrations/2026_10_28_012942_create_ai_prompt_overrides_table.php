<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-edited AI hint per field (plan 12): one text per FieldRegistry key,
 * used for every UI locale. A row exists only while the field is customized
 * (reset deletes it); rows of keys no longer registered are ignored.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_prompt_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('field_key', 100)->unique();
            $table->text('prompt');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_prompt_overrides');
    }
};
