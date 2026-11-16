<?php

namespace App\Support\Media;

use RuntimeException;

/**
 * An image the cleaner refuses (plan 32 PW-1). Carries only a fixed reason
 * key (lang admin.ai_wizard.image_errors.{reason}), never a path, a file
 * name or a decoder message.
 */
final class ImageRejected extends RuntimeException
{
    public const REASONS = ['type', 'size', 'dimensions', 'memory', 'animated', 'corrupt'];

    public function __construct(public readonly string $reason)
    {
        parent::__construct('Image rejected: '.(in_array($reason, self::REASONS, true) ? $reason : 'corrupt'));
    }

    public function messageKey(): string
    {
        return 'admin.ai_wizard.image_errors.'.(in_array($this->reason, self::REASONS, true) ? $this->reason : 'corrupt');
    }
}
