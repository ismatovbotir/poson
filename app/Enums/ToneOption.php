<?php

namespace App\Enums;

/**
 * Plan 46: the tone preset of an agent's replies.
 */
enum ToneOption: string
{
    case Formal = 'formal';
    case WarmProfessional = 'warm_professional';

    public function label(): string
    {
        return __("enums.tone_option.{$this->value}");
    }
}
