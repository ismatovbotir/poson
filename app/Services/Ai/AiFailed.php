<?php

namespace App\Services\Ai;

use RuntimeException;
use Throwable;

/**
 * A chat model call failed (AI-3). Carries only the short class name of the
 * underlying error and the HTTP status: never its message, body, request or
 * headers, and never the original exception as "previous", so no key,
 * prompt or answer can reach a log or failed_jobs.
 */
final class AiFailed extends RuntimeException
{
    public function __construct(public readonly string $errorClass, public readonly ?int $status = null)
    {
        parent::__construct('AI request failed: '.$errorClass.($status !== null ? ' (HTTP '.$status.')' : ''));
    }

    public static function from(Throwable $error): self
    {
        $status = property_exists($error, 'status') && is_int($error->status) ? $error->status : null;

        return new self(class_basename($error), $status);
    }
}
