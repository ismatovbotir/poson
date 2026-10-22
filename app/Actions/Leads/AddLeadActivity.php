<?php

namespace App\Actions\Leads;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * A note or a logged call on a lead's timeline, by a staff user.
 */
class AddLeadActivity
{
    public const BODY_MAX = 2000;

    /**
     * @throws ValidationException
     */
    public function handle(Lead $lead, ActivityType $type, string $body, User $actor): Activity
    {
        if (! in_array($type, ActivityType::manual(), true)) {
            throw ValidationException::withMessages(['activity_type' => __('crm.leads.errors.activity_type_invalid')]);
        }

        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages(['activity_body' => __('validation.required', ['attribute' => __('crm.leads.activity.body')])]);
        }

        if (mb_strlen($body) > self::BODY_MAX) {
            throw ValidationException::withMessages(['activity_body' => __('validation.max.string', ['attribute' => __('crm.leads.activity.body'), 'max' => self::BODY_MAX])]);
        }

        $activity = $lead->activities()->create([
            'user_id' => $actor->id,
            'type' => $type,
            'body' => $body,
            'created_at' => now(),
        ]);

        $lead->touch();

        return $activity;
    }
}
