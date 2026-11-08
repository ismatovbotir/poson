<?php

namespace App\Actions\Repairs;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Repair;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * A note on a repair's timeline (callers authorize RepairPolicy::update first).
 */
class AddRepairActivity
{
    public const BODY_MAX = 2000;

    /**
     * @throws ValidationException
     */
    public function handle(Repair $repair, string $body, User $actor): Activity
    {
        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages(['activity_body' => __('validation.required', ['attribute' => __('repairs.activity.body')])]);
        }

        if (mb_strlen($body) > self::BODY_MAX) {
            throw ValidationException::withMessages(['activity_body' => __('validation.max.string', ['attribute' => __('repairs.activity.body'), 'max' => self::BODY_MAX])]);
        }

        $activity = $repair->activities()->create([
            'user_id' => $actor->id,
            'type' => ActivityType::Note,
            'body' => $body,
            'created_at' => now(),
        ]);

        $repair->touch();

        return $activity;
    }
}
