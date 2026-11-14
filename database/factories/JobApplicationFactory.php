<?php

namespace Database\Factories;

use App\Enums\JobApplicationCvStatus;
use App\Enums\JobApplicationStatus;
use App\Enums\VacancyDepartment;
use App\Models\JobApplication;
use App\Models\Vacancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobApplication>
 */
class JobApplicationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vacancy_id' => Vacancy::factory(),
            'department' => VacancyDepartment::Service,
            'status' => JobApplicationStatus::New,
            'name' => fake()->name(),
            'phone' => '+998901234567',
            'email' => null,
            'message' => null,
            'locale' => 'uz',
            'cv_status' => JobApplicationCvStatus::None,
        ];
    }

    /**
     * The department follows the vacancy (as SubmitJobApplication copies it).
     */
    public function configure(): static
    {
        return $this->afterMaking(function (JobApplication $application): void {
            if ($application->vacancy_id !== null) {
                $department = Vacancy::query()->whereKey($application->vacancy_id)->value('department');

                if ($department !== null) {
                    $application->department = $department instanceof VacancyDepartment ? $department : VacancyDepartment::from((string) $department);
                }
            }
        });
    }

    /**
     * An application to the given vacancy (department copied from it).
     */
    public function forVacancy(Vacancy $vacancy): static
    {
        return $this->state(fn (): array => ['vacancy_id' => $vacancy->id, 'department' => $vacancy->department]);
    }

    /**
     * An open application to a department (no vacancy).
     */
    public function openApplication(VacancyDepartment $department = VacancyDepartment::Sales): static
    {
        return $this->state(fn (): array => ['vacancy_id' => null, 'department' => $department]);
    }
}
