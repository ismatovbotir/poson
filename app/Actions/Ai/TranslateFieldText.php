<?php

namespace App\Actions\Ai;

use App\Enums\AiFeature;
use App\Models\AiRequest;
use App\Models\User;
use App\Services\Ai\TextTask;
use App\Support\Ai\AiContext;
use Illuminate\Validation\ValidationException;

/**
 * Queue an AI translation of a form field from the active locale into the
 * other locales (plan 02, A3). The source text is the unsaved field value.
 */
class TranslateFieldText extends QueueTextRequest
{
    public function handle(User $user, string $fieldKey, string $hint, AiContext $context, string $sourceLocale, string $sourceText): AiRequest
    {
        $sourceLocale = $this->locale($sourceLocale);

        if (trim(strip_tags($sourceText)) === '' || mb_strlen($sourceText) > self::SOURCE_MAX) {
            throw ValidationException::withMessages(['source' => __('ai.errors.source_empty')]);
        }

        return $this->queue($user, new TextTask(AiFeature::TextTranslate, $fieldKey, $hint, $context, $sourceLocale, $sourceText));
    }
}
