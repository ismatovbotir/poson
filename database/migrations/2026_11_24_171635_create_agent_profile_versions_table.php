<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 41 §5: immutable versions of an agent's teaching (instructions,
 * knowledge, rules, examples, model, output limit). Every save is a new
 * row; publishing points agent_profiles.current_version_id at one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_profile_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_profile_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('instructions');
            $table->mediumText('knowledge')->nullable();
            $table->text('rules')->nullable();
            $table->json('examples')->nullable();
            $table->string('model', 40);
            $table->unsignedSmallInteger('max_tokens');
            $table->string('change_note', 255)->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['agent_profile_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_profile_versions');
    }
};
