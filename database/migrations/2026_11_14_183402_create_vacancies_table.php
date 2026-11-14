<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 31: job vacancies. Texts are plain text per locale (json); every
 * locale has its own unique slug (a plain column, SQLite-portable). The
 * slug uniqueness across the three columns is enforced in SaveVacancy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacancies', function (Blueprint $table) {
            $table->id();
            $table->string('department', 20);
            $table->string('location', 20);
            $table->string('employment_type', 20);
            $table->json('title');
            $table->json('summary');
            $table->json('description');
            $table->json('requirements')->nullable();
            $table->json('offer')->nullable();
            $table->json('meta_title')->nullable();
            $table->json('meta_description')->nullable();
            $table->string('slug_uz', 120)->unique();
            $table->string('slug_ru', 120)->nullable()->unique();
            $table->string('slug_en', 120)->nullable()->unique();
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->date('valid_through')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['is_published', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacancies');
    }
};
