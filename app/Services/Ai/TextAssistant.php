<?php

namespace App\Services\Ai;

/**
 * Writes form field texts in uz, ru and en (plan 02 §1.2). Implemented by
 * ClaudeTextAssistant (laravel/ai) and OpenRouterTextAssistant (plan 10),
 * resolved per provider through TextAssistants::for(); tests bind fakes.
 * Later: describeImage() (phase 2) and extractProduct() (phase 3) as
 * separate contracts.
 *
 * Both methods run on $model (the model stored on the request row) and
 * throw AiException with a safe code on any failure.
 */
interface TextAssistant
{
    /**
     * Write the field in every supported locale.
     */
    public function generate(TextTask $task, string $model): TextResult;

    /**
     * Translate $task->sourceText from $task->sourceLocale into the other
     * locales (the source locale comes back unchanged).
     */
    public function translate(TextTask $task, string $model): TextResult;
}
