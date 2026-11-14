<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 30 AI-10: the AI instructions of the chat bot are audited with their
 * old and new text (they are not secret: the system prompt is treated as
 * public). Every other settings change keeps both columns null; secrets are
 * never written here (SettingsAudit::recordText is used for that one key).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings_audits', function (Blueprint $table): void {
            $table->text('old_text')->nullable()->after('ip');
            $table->text('new_text')->nullable()->after('old_text');
        });
    }

    public function down(): void
    {
        Schema::table('settings_audits', function (Blueprint $table): void {
            $table->dropColumn(['old_text', 'new_text']);
        });
    }
};
