<?php

namespace App\Actions\Ai;

use App\Enums\AiErrorCode;
use App\Enums\AiRequestStatus;
use App\Models\AiRequest;

/**
 * An open request that waited too long is marked failed with "timeout", so
 * the assistant can offer Retry: pending counts from created_at (no queue
 * worker), running from started_at (the worker died), so a request that
 * sat in a backlog isn't failed while it runs. The update is conditional
 * on the status it was judged in. Returns whether it was failed.
 */
class FailStaleAiRequest
{
    public function __construct(protected ProcessAiRequest $process) {}

    public function handle(AiRequest $request): bool
    {
        $since = match ($request->status) {
            AiRequestStatus::Pending => $request->created_at,
            AiRequestStatus::Running => $request->started_at ?? $request->created_at,
            default => null,
        };

        if ($since === null || $since->diffInSeconds(now(), true) < AiRequest::staleAfterSeconds()) {
            return false;
        }

        return $this->process->fail($request, AiErrorCode::Timeout, from: $request->status);
    }
}
