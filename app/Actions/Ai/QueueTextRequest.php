<?php

namespace App\Actions\Ai;

use App\Enums\AiErrorCode;
use App\Enums\AiProvider;
use App\Enums\AiRequestStatus;
use App\Jobs\RunAiRequest;
use App\Models\AiRequest;
use App\Models\User;
use App\Services\Ai\AiAssist;
use App\Services\Ai\AiException;
use App\Services\Ai\AiQuota;
use App\Services\Ai\ClaudeTextAssistant;
use App\Services\Ai\TextTask;
use App\Support\Ai\FieldRegistry;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Shared steps of GenerateFieldText and TranslateFieldText (plan 02 §1.3):
 * authorize ai.use → AI on and configured → quota (a refused request is
 * logged as "rejected") → ai_requests row "pending" → RunAiRequest on the
 * "ai" queue. The entity is never saved here: the manager applies the
 * result to the form and saves it with the normal validation.
 */
abstract class QueueTextRequest
{
    public const HINT_MAX = 2000;

    public const SOURCE_MAX = 20000;

    /**
     * @throws AiException disabled / not_configured / quota_exceeded / budget_exceeded
     * @throws ValidationException unknown field or empty hint
     */
    protected function queue(User $user, TextTask $task): AiRequest
    {
        Gate::forUser($user)->authorize('create', AiRequest::class);

        if (! FieldRegistry::has($task->fieldKey)) {
            throw ValidationException::withMessages(['field' => __('ai.errors.unknown_field')]);
        }

        if (trim($task->hint) === '' || mb_strlen($task->hint) > self::HINT_MAX) {
            throw ValidationException::withMessages(['hint' => __('ai.errors.hint_invalid', ['max' => self::HINT_MAX])]);
        }

        AiAssist::ensureUsable();

        $attributes = [
            'user_id' => $user->id,
            'feature' => $task->feature,
            'provider' => AiProvider::Anthropic,
            'model' => ClaudeTextAssistant::model(),
            'field_key' => $task->fieldKey,
            'subject_type' => $task->context->subjectType,
            'subject_id' => $task->context->subjectId,
            'source_locale' => $task->sourceLocale,
            'prompt' => trim($task->hint),
        ];

        // Check + insert under one lock, so parallel requests can't both pass the quota or the budget.
        try {
            $request = Cache::lock(AiQuota::LOCK, 15)->block((int) config('ai-assist.lock_wait', 5), function () use ($user, $attributes): AiRequest {
                try {
                    AiQuota::ensureAvailable($user, $attributes['model']);
                } catch (AiException $exception) {
                    AiRequest::query()->create([...$attributes, 'status' => AiRequestStatus::Rejected, 'error_code' => $exception->errorCode, 'finished_at' => now()]);

                    throw $exception;
                }

                return AiRequest::query()->create([...$attributes, 'status' => AiRequestStatus::Pending]);
            });
        } catch (LockTimeoutException) {
            throw AiException::for(AiErrorCode::RateLimited);
        }

        RunAiRequest::dispatch($request->id, $task)->onQueue((string) config('ai-assist.queue', 'ai'));

        return $request;
    }

    /**
     * Supported locale or a validation error.
     */
    protected function locale(string $locale): string
    {
        if (! in_array($locale, config('app.supported_locales'), true)) {
            throw ValidationException::withMessages(['locale' => __('ai.errors.unknown_locale')]);
        }

        return $locale;
    }

    /**
     * Codes a refused request may carry.
     *
     * @return list<AiErrorCode>
     */
    public static function refusalCodes(): array
    {
        return [AiErrorCode::Disabled, AiErrorCode::NotConfigured, AiErrorCode::QuotaExceeded, AiErrorCode::BudgetExceeded];
    }
}
