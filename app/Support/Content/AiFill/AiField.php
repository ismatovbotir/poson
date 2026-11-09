<?php

namespace App\Support\Content\AiFill;

use App\Enums\AiFieldKind;

/**
 * One translatable text field of a content form that the "Fill from AI
 * answer" box may write (plan 29). Immutable. "max" repeats the form's
 * validation rule (a test keeps them equal); "key" is the form property
 * holding the uz/ru/en array.
 */
final readonly class AiField
{
    public function __construct(
        public string $key,
        public string $labelKey,
        public AiFieldKind $kind,
        public int $max,
        public bool $uzRequired = false,
        public ?string $hint = null,
        public bool $multiline = false,
    ) {}

    /**
     * English label for the prompt (the prompt text is always English).
     */
    public function promptLabel(): string
    {
        return __($this->labelKey, [], 'en');
    }
}
