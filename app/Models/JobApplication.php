<?php

namespace App\Models;

use App\Enums\JobApplicationCvStatus;
use App\Enums\JobApplicationStatus;
use App\Enums\VacancyDepartment;
use Database\Factories\JobApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * An application to a vacancy (plan 31). Personal data of a candidate: kept
 * until deleted by hand (owner decision 2026-11-14, no pruning), erased by
 * App\Actions\Careers\DeleteJobApplication (phase B). The CV bytes are never
 * stored: Telegram's file_id after the forward, or for at most 24 hours the
 * path of an encrypted pending copy. Both are hidden from serialization and
 * never go to the browser. No IP, no UTM.
 *
 * @property int $id
 * @property int|null $vacancy_id null = an open application to a department
 * @property VacancyDepartment $department
 * @property JobApplicationStatus $status
 * @property string $name
 * @property string $phone
 * @property string|null $email
 * @property string|null $message
 * @property string $locale
 * @property JobApplicationCvStatus $cv_status
 * @property string|null $cv_pending_path
 * @property string|null $cv_file_id
 * @property string|null $cv_file_unique_id
 * @property string|null $cv_original_name
 * @property string|null $cv_mime
 * @property int|null $cv_size
 * @property Carbon|null $cv_forwarded_at
 * @property int|null $telegram_message_id
 * @property string|null $internal_note
 * @property Carbon|null $status_changed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['vacancy_id', 'department', 'status', 'name', 'phone', 'email', 'message', 'locale', 'cv_status', 'cv_pending_path', 'cv_file_id', 'cv_file_unique_id', 'cv_original_name', 'cv_mime', 'cv_size', 'cv_forwarded_at', 'telegram_message_id', 'internal_note', 'status_changed_at'])]
class JobApplication extends Model
{
    /** @use HasFactory<JobApplicationFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $hidden = ['cv_file_id', 'cv_pending_path', 'cv_file_unique_id'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'department' => VacancyDepartment::class,
            'status' => JobApplicationStatus::class,
            'cv_status' => JobApplicationCvStatus::class,
            'cv_size' => 'integer',
            'cv_forwarded_at' => 'datetime',
            'telegram_message_id' => 'integer',
            'status_changed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Vacancy, $this>
     */
    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class);
    }

    /**
     * An open application to a department (no vacancy).
     */
    public function isOpenApplication(): bool
    {
        return $this->vacancy_id === null;
    }

    public function departmentLabel(): string
    {
        return $this->department->label();
    }

    /**
     * @return MorphMany<Activity, $this>
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }
}
