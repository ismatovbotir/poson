<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 28 C1: the rule bot's rules (answer, action, params, buttons per
 * locale). Edited in admin from C2; system rows come from chatbot:install-defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('name', 120);
            $table->string('action', 20);
            $table->json('params')->nullable();
            $table->json('answer')->nullable();
            $table->json('buttons')->nullable();
            $table->boolean('on_web')->default(true);
            $table->boolean('on_telegram')->default(true);
            $table->boolean('in_menu')->default(false);
            $table->smallInteger('menu_sort')->default(0);
            $table->smallInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'in_menu', 'menu_sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_rules');
    }
};
