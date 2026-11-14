<?php

namespace Database\Factories;

use App\Enums\EmploymentType;
use App\Enums\Region;
use App\Enums\VacancyDepartment;
use App\Models\Vacancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vacancy>
 */
class VacancyFactory extends Factory
{
    /**
     * Define the model's default state: a complete draft in all three locales.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1, 999_999);

        return [
            'department' => VacancyDepartment::Service,
            'location' => Region::TashkentCity,
            'employment_type' => EmploymentType::FullTime,
            'title' => ['uz' => "Muhandis {$n}", 'ru' => "Инженер {$n}", 'en' => "Engineer {$n}"],
            'summary' => ['uz' => 'Qisqa tavsif', 'ru' => 'Краткое описание', 'en' => 'Short summary'],
            'description' => ['uz' => "Birinchi band.\n\nIkkinchi band.", 'ru' => "Первый абзац.\n\nВторой абзац.", 'en' => "First paragraph.\n\nSecond paragraph."],
            'requirements' => ['uz' => ['Tajriba'], 'ru' => ['Опыт'], 'en' => ['Experience']],
            'offer' => ['uz' => ['Rasmiy ish'], 'ru' => ['Официальное трудоустройство'], 'en' => ['Official employment']],
            'slug_uz' => "muhandis-{$n}",
            'slug_ru' => "inzhener-{$n}",
            'slug_en' => "engineer-{$n}",
            'is_published' => false,
            'published_at' => null,
            'valid_through' => null,
            'sort' => 0,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => ['is_published' => true, 'published_at' => now()]);
    }

    public function expired(): static
    {
        return $this->published()->state(fn (): array => ['valid_through' => now('Asia/Tashkent')->subDay()->toDateString()]);
    }

    /**
     * Only the uz texts and slug (cannot be published).
     */
    public function onlyUzbek(): static
    {
        return $this->state(fn (array $attributes): array => [
            'title' => ['uz' => $attributes['title']['uz']],
            'summary' => ['uz' => 'Qisqa tavsif'],
            'description' => ['uz' => 'Tavsif'],
            'requirements' => null,
            'offer' => null,
            'slug_ru' => null,
            'slug_en' => null,
        ]);
    }
}
