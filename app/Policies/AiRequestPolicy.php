<?php

namespace App\Policies;

use App\Models\AiRequest;
use App\Models\User;

/**
 * Admin AI assist (plan 02 §4). ai.use: Admin + Content manager (send
 * requests, see their own results in the assistant). ai-requests.viewAny:
 * the usage screen with everyone's requests (Admin). Admins pass through
 * Gate::before. Rows are written only by App\Actions\Ai and the job.
 */
class AiRequestPolicy
{
    /**
     * The usage screen (all users' requests).
     */
    public function viewAny(User $user): bool
    {
        return $user->can('ai-requests.viewAny');
    }

    /**
     * One request: your own (assistant panel) or any with the usage permission.
     */
    public function view(User $user, AiRequest $aiRequest): bool
    {
        return ($aiRequest->user_id === $user->id && $user->can('ai.use')) || $user->can('ai-requests.viewAny');
    }

    /**
     * Send a request to the AI assistant.
     */
    public function create(User $user): bool
    {
        return $user->can('ai.use');
    }
}
