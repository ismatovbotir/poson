<?php

namespace App\Actions\Catalog\Ai;

use RuntimeException;

/**
 * A stage request that must not be sent (plan 32 M4): RunAiDraftStage ends
 * the draft as failed with the fixed error key, before any cap reservation
 * or paid call. Carries only the key, never data.
 */
final class AiStageSkipped extends RuntimeException
{
    public const ERRORS = ['no_images'];

    public function __construct(public readonly string $error)
    {
        parent::__construct('AI stage skipped: '.(in_array($error, self::ERRORS, true) ? $error : 'error'));
    }

    public function errorKey(): string
    {
        return in_array($this->error, self::ERRORS, true) ? $this->error : 'error';
    }
}
