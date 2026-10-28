<?php

namespace App\Services\Ai;

use App\Enums\AiFeature;
use App\Support\Ai\AiContext;
use App\Support\Ai\FieldRegistry;
use App\Support\Ai\FieldSpec;
use InvalidArgumentException;

/**
 * Input of TextAssistant: which field, the final hint (as edited by the
 * manager), the record context and, for translations, the source text.
 * Queued with RunAiRequest, so it only holds plain values.
 */
final readonly class TextTask
{
    public function __construct(
        public AiFeature $feature,
        public string $fieldKey,
        public string $hint,
        public AiContext $context,
        public ?string $sourceLocale = null,
        public string $sourceText = '',
    ) {}

    /**
     * @throws InvalidArgumentException for a retired key: check isRegistered() first
     */
    public function field(): FieldSpec
    {
        return FieldRegistry::get($this->fieldKey);
    }

    /**
     * The field is still in FieldRegistry (a queued request may carry a key
     * removed since; ProcessAiRequest then fails it with field_retired).
     */
    public function isRegistered(): bool
    {
        return FieldRegistry::has($this->fieldKey);
    }

    public function isTranslation(): bool
    {
        return $this->feature === AiFeature::TextTranslate;
    }
}
