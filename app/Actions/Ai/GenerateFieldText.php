<?php

namespace App\Actions\Ai;

use App\Enums\AiFeature;
use App\Models\AiRequest;
use App\Models\User;
use App\Services\Ai\TextTask;
use App\Support\Ai\AiContext;

/**
 * Queue an AI draft of a form field in uz, ru and en (plan 02, A3).
 */
class GenerateFieldText extends QueueTextRequest
{
    public function handle(User $user, string $fieldKey, string $hint, AiContext $context): AiRequest
    {
        return $this->queue($user, new TextTask(AiFeature::TextGenerate, $fieldKey, $hint, $context));
    }
}
