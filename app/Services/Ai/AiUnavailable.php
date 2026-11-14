<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * No API key is configured: AI answers are off and no request was made.
 */
final class AiUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('AI answers are not configured.');
    }
}
