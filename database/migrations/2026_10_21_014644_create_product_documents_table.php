<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product documents (plan 03 D11): datasheets, manuals, drivers, ... Either
 * a file on the private "local" disk (path, original_name, size) or an
 * external https link (url) — exactly one of path / url, enforced by
 * SaveProductDocument. sha256 = checksum of the uploaded file (shown on the
 * product page). locale null = the document applies to every language.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->json('title');
            $table->string('type', 20);
            $table->string('locale', 2)->nullable();
            $table->string('path')->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('original_name')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'sort']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_documents');
    }
};
